<?php

namespace App\Jobs;

use App\Ai\Agents\QAAuditorAgent;
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

class QAAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public Subtask $subtask,
    ) {
        $this->queue = 'qa';
    }

    public function handle(): void
    {
        if (! SystemSetting::isDevelopmentEnabled()) {
            Log::info("QAAuditJob: Development is globally disabled. Skipping subtask {$this->subtask->id}.");

            return;
        }

        Log::info("QAAuditJob: Auditing subtask {$this->subtask->id} — {$this->subtask->title}");

        $project = $this->subtask->task->project;
        $projectPath = $project->local_path;

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_HIGH);

            $response = (new QAAuditorAgent($projectPath))->prompt(
                $prompt,
                provider: $aiConfig['provider'],
                model: $aiConfig['model'],
            );
            $auditResult = $response->data;
        } catch (\Throwable $e) {
            Log::error("QAAuditJob: Failed to get/parse audit response for subtask {$this->subtask->id}. Error: {$e->getMessage()}");
            $this->rejectSubtask(['approved' => false, 'issues' => ["Audit parsing failed: {$e->getMessage()}"]]);

            return;
        }

        if ($auditResult['approved'] ?? false) {
            $this->approveSubtask($auditResult);
        } else {
            $this->rejectSubtask($auditResult);
        }
    }

    private function buildPrompt(): string
    {
        $subPrd = $this->subtask->sub_prd_payload ?? [];
        $objective = $subPrd['objective'] ?? 'Não definido';
        $criteria = implode("\n", array_map(fn ($c) => "- {$c}", $subPrd['acceptance_criteria'] ?? []));
        $resultLog = mb_substr($this->subtask->result_log ?? '', 0, 10000);
        $diff = mb_substr($this->subtask->result_diff ?? '', 0, 20000);

        return <<<PROMPT
## Sub-PRD Auditado

**Título:** {$this->subtask->title}
**Agente:** {$this->subtask->assigned_agent}

**Objetivo do Sub-PRD:**
{$objective}

**Critérios de Aceite:**
{$criteria}

---

## Log de Execução do Agente (últimas 10.000 chars):
{$resultLog}

---

## Git Diff (mudanças realizadas):
{$diff}

---

Avalie se o Sub-PRD foi implementado corretamente e retorne o JSON de auditoria conforme suas instruções.
PROMPT;
    }

    private function approveSubtask(?array $auditResult = null): void
    {
        Log::info("QAAuditJob: Subtask {$this->subtask->id} APPROVED");

        // Fazer git commit e salvar o hash para rastreabilidade e rollback
        $commitHash = $this->commitAndCaptureHash();

        $this->subtask->transitionTo(SubtaskStatus::Success, 'qa-auditor', $auditResult);
        $this->subtask->update([
            'completed_at' => now(),
            'commit_hash' => $commitHash,
        ]);

        $this->checkTaskCompletion();
        $this->dispatchNextSubtasks();
    }

    /**
     * Faz git add + commit no projeto alvo e retorna o hash do commit.
     * O hash é salvo na subtask para permitir rollback preciso via `git revert <hash>`.
     */
    private function commitAndCaptureHash(): ?string
    {
        $workDir = $this->subtask->task->project->local_path ?? null;
        if (! $workDir || ! is_dir("{$workDir}/.git")) {
            return null;
        }

        try {
            $statusResult = Process::path($workDir)->timeout(10)->run(['git', 'status', '--porcelain']);
            if (trim($statusResult->output()) === '') {
                $hashResult = Process::path($workDir)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
                $hash = trim($hashResult->output());

                return $hash ?: null;
            }

            // Stage all changes
            Process::path($workDir)->timeout(30)->run(['git', 'add', '-A']);

            // Commit with descriptive message
            $message = "ai-dev: {$this->subtask->title} [subtask:{$this->subtask->id}]";
            $commitResult = Process::path($workDir)->timeout(30)
                ->run(['git', 'commit', '-m', $message]);

            if (! $commitResult->successful()) {
                Log::warning("QAAuditJob: git commit failed for subtask {$this->subtask->id}: {$commitResult->errorOutput()}");

                return null;
            }

            // Capture the commit hash
            $hashResult = Process::path($workDir)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $hash = trim($hashResult->output());

            Log::info("QAAuditJob: Subtask {$this->subtask->id} committed as {$hash}");

            return $hash ?: null;
        } catch (\Throwable $e) {
            Log::error("QAAuditJob: git error for subtask {$this->subtask->id}: {$e->getMessage()}");

            return null;
        }
    }

    private function rejectSubtask(array $auditResult): void
    {
        Log::warning("QAAuditJob: Subtask {$this->subtask->id} REJECTED");

        $feedback = $auditResult['issues'] ?? $auditResult['raw_response'] ?? 'QA audit failed';
        if (is_array($feedback)) {
            $feedback = json_encode($feedback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $this->subtask->update(['qa_feedback' => $feedback]);

        if ($this->subtask->retry_count < $this->subtask->max_retries) {
            // Retry: qa_audit → pending → running
            $this->subtask->transitionTo(SubtaskStatus::Pending, 'qa-auditor', [
                'reason' => 'QA rejection, retry',
                'retry' => $this->subtask->retry_count + 1,
            ]);
            $this->subtask->increment('retry_count');

            ProcessSubtaskJob::dispatch($this->subtask);
        } else {
            $this->subtask->transitionTo(SubtaskStatus::Error, 'qa-auditor', [
                'reason' => 'QA rejection, max retries exceeded',
            ]);
            $this->subtask->update(['completed_at' => now()]);

            $this->checkTaskCompletion();
        }
    }

    private function checkTaskCompletion(): void
    {
        $task = $this->subtask->task;
        $allSubtasks = $task->subtasks()->get();

        $allFinished = $allSubtasks->every(fn ($s) => in_array($s->status->value, ['success', 'error']));

        if (! $allFinished) {
            return;
        }

        $allSuccess = $allSubtasks->every(fn ($s) => $s->status === SubtaskStatus::Success);

        if ($allSuccess) {
            // All subtasks passed QA → transition task to testing → completed
            $task->transitionTo(TaskStatus::QaAudit, 'qa-auditor');
            $task->transitionTo(TaskStatus::Testing, 'qa-auditor');
            $task->transitionTo(TaskStatus::Completed, 'system');

            // Salvar o commit_hash final da task (último commit de subtask)
            $lastCommitHash = $allSubtasks->whereNotNull('commit_hash')->last()?->commit_hash;
            $task->update([
                'completed_at' => now(),
                'commit_hash' => $lastCommitHash,
            ]);

            Log::info("Task {$task->id} COMPLETED successfully (commit: {$lastCommitHash})");
        } else {
            // Some subtasks failed
            $errorCount = $allSubtasks->where('status', SubtaskStatus::Error)->count();
            $task->update(['error_log' => "{$errorCount} subtask(s) failed QA audit"]);

            if ($task->canRetry()) {
                $task->increment('retry_count');
                $task->transitionTo(TaskStatus::Rollback, 'system', ['reason' => 'subtask QA failures']);
                $task->transitionTo(TaskStatus::Pending, 'system', ['reason' => 'retry']);
                OrchestratorJob::dispatch($task);
            } else {
                $task->transitionTo(TaskStatus::Rollback, 'system', ['reason' => 'subtask QA failures']);
                $task->transitionTo(TaskStatus::Failed, 'system', ['reason' => 'max retries exceeded']);
            }
        }
    }

    private function dispatchNextSubtasks(): void
    {
        $task = $this->subtask->task;
        $pendingSubtasks = $task->subtasks()
            ->where('status', SubtaskStatus::Pending)
            ->orderBy('execution_order')
            ->get();

        $reservedLocks = [];

        foreach ($pendingSubtasks as $subtask) {
            $locks = $subtask->file_locks ?? [];
            $hasReservedConflict = $locks !== [] && array_intersect($locks, $reservedLocks) !== [];

            if ($subtask->areDependenciesMet() && ! $subtask->hasFileLockConflict() && ! $hasReservedConflict) {
                ProcessSubtaskJob::dispatch($subtask);
                $reservedLocks = array_values(array_unique([...$reservedLocks, ...$locks]));
            }
        }
    }
}
