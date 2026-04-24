<?php

namespace App\Jobs;

use App\Ai\Agents\SpecialistAgent;
use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\SystemSetting;
use App\Services\AiRuntimeConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ProcessSubtaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public Subtask $subtask,
    ) {
        $this->queue = 'subtasks';
    }

    public function handle(): void
    {
        if (! SystemSetting::isDevelopmentEnabled()) {
            Log::info("ProcessSubtaskJob: Development is globally disabled. Skipping subtask {$this->subtask->id}.");

            return;
        }

        Log::info("ProcessSubtaskJob: Processing subtask {$this->subtask->id} — {$this->subtask->title}");

        $this->subtask->transitionTo(SubtaskStatus::Running, $this->subtask->assigned_agent);
        $this->subtask->update(['started_at' => now()]);

        $project = $this->subtask->task->project;
        $workDir = $project->local_path;

        if (! $workDir || ! is_dir($workDir)) {
            $this->failSubtask("Project directory not found: {$workDir}");

            return;
        }

        $prompt = $this->buildPrompt($workDir);

        try {
            $agent = new SpecialistAgent($workDir, $this->subtask->assigned_agent);
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_HIGH);
            $response = $agent->prompt(
                $prompt,
                provider: $aiConfig['provider'],
                model: $aiConfig['model'],
            );

            $resultLog = (string) $response;
        } catch (\Throwable $e) {
            Log::error("ProcessSubtaskJob: Failed for subtask {$this->subtask->id}", ['error' => $e->getMessage()]);
            $this->failSubtask("Agent error: {$e->getMessage()}");

            return;
        }

        // Capture git diff for QA audit
        $diff = '';
        if (is_dir("{$workDir}/.git")) {
            $unstaged = Process::path($workDir)->timeout(30)->run(['git', 'diff']);
            $staged = Process::path($workDir)->timeout(30)->run(['git', 'diff', '--cached']);
            $stat = Process::path($workDir)->timeout(30)->run(['git', 'diff', '--stat']);

            $diff = implode("\n\n", array_filter([
                $stat->output() ? "## Git Diff Stat\n".$stat->output() : null,
                $unstaged->output() ? "## Unstaged Diff\n".$unstaged->output() : null,
                $staged->output() ? "## Staged Diff\n".$staged->output() : null,
            ]));
        }

        $this->subtask->update([
            'result_log' => mb_substr($resultLog, 0, 65000),
            'result_diff' => mb_substr($diff, 0, 65000),
            'completed_at' => now(),
        ]);

        $this->subtask->transitionTo(SubtaskStatus::QaAudit, $this->subtask->assigned_agent);

        QAAuditJob::dispatch($this->subtask);

        Log::info("ProcessSubtaskJob: Completed subtask {$this->subtask->id}, dispatched QAAuditJob");
    }

    private function buildPrompt(string $workDir): string
    {
        $subPrd = $this->subtask->sub_prd_payload ?? [];
        $objective = $subPrd['objective'] ?? 'Não definido';
        $criteria = $this->listItems($subPrd['acceptance_criteria'] ?? []);
        $constraints = $this->listItems($subPrd['constraints'] ?? []);
        $context = $subPrd['context'] ?? 'Nenhum contexto adicional';
        $files = $this->listItems($subPrd['files'] ?? []);

        return <<<PROMPT
## Sub-PRD a implementar

**Subtask ID:** {$this->subtask->id}
**Título:** {$this->subtask->title}
**Agente:** {$this->subtask->assigned_agent}
**Diretório do projeto:** {$workDir}

**Objetivo:**
{$objective}

**Critérios de Aceite:**
{$criteria}

**Restrições:**
{$constraints}

**Contexto Técnico:**
{$context}

**Arquivos envolvidos:**
{$files}

---

Implemente o Sub-PRD acima seguindo o fluxo de trabalho das suas instruções.
PROMPT;
    }

    private function listItems(array $items): string
    {
        if (empty($items)) {
            return '- (nenhum)';
        }

        return implode("\n", array_map(fn ($item) => "- {$item}", $items));
    }

    private function failSubtask(string $reason): void
    {
        Log::error("ProcessSubtaskJob: Subtask {$this->subtask->id} failed — {$reason}");

        $this->subtask->update([
            'result_log' => $reason,
            'completed_at' => now(),
        ]);

        $this->subtask->transitionTo(SubtaskStatus::Error, $this->subtask->assigned_agent, ['reason' => $reason]);

        $this->checkParentTaskStatus();
    }

    private function checkParentTaskStatus(): void
    {
        $task = $this->subtask->task;
        $allSubtasks = $task->subtasks;

        $hasErrors = $allSubtasks->where('status', SubtaskStatus::Error)->isNotEmpty();
        $allDone = $allSubtasks->every(
            fn ($s) => in_array($s->status, [SubtaskStatus::Success, SubtaskStatus::Error])
        );

        if ($hasErrors && $allDone) {
            if ($task->canRetry()) {
                $task->increment('retry_count');
                $task->transitionTo(TaskStatus::Rollback, 'system', ['reason' => 'subtask errors']);
                $task->transitionTo(TaskStatus::Pending, 'system', ['reason' => 'retry after subtask errors']);
                OrchestratorJob::dispatch($task);
            } else {
                $task->transitionTo(TaskStatus::Rollback, 'system', ['reason' => 'subtask errors, max retries']);
                $task->transitionTo(TaskStatus::Failed, 'system', ['reason' => 'max retries exceeded']);
            }
        }
    }
}
