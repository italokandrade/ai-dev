<?php

namespace App\Jobs;

use App\Ai\Agents\OrchestratorAgent;
use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public Task $task,
    ) {
        $this->queue = 'orchestrator';
    }

    public function handle(): void
    {
        Log::info("OrchestratorJob: Processing task {$this->task->id} — {$this->task->title}");

        // Transition: pending → in_progress
        $this->task->transitionTo(TaskStatus::InProgress, 'orchestrator');
        $this->task->update(['started_at' => now()]);

        $prompt = $this->buildPrompt();

        try {
            $response = OrchestratorAgent::make()->prompt($prompt);
            $raw = trim((string) $response);

            // Strip markdown fences if present
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);

            $subPrds = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($subPrds) || empty($subPrds)) {
                throw new \RuntimeException('Orchestrator returned empty or invalid Sub-PRD array.');
            }
        } catch (\Throwable $e) {
            Log::error("OrchestratorJob: Failed for task {$this->task->id}", ['error' => $e->getMessage()]);
            $this->failTask("AI generation failed: {$e->getMessage()}");

            return;
        }

        $order = 1;
        foreach ($subPrds as $subPrd) {
            Subtask::create([
                'task_id' => $this->task->id,
                'title' => $subPrd['title'] ?? "Subtask {$order}",
                'sub_prd_payload' => $subPrd,
                'status' => SubtaskStatus::Pending,
                'assigned_agent' => $subPrd['assigned_agent'] ?? 'backend-specialist',
                'dependencies' => $subPrd['dependencies'] ?? null,
                'execution_order' => $subPrd['execution_order'] ?? $order,
                'file_locks' => $subPrd['files'] ?? null,
            ]);
            $order++;
        }

        Log::info('OrchestratorJob: Created '.count($subPrds)." subtasks for task {$this->task->id}");

        $this->dispatchReadySubtasks();
    }

    private function buildPrompt(): string
    {
        $prd = $this->task->prd_payload ?? [];
        $project = $this->task->project;
        $objective = $prd['objective'] ?? 'Não definido';
        $criteria = $this->listItems($prd['acceptance_criteria'] ?? []);
        $constraints = $this->listItems($prd['constraints'] ?? []);
        $knowledge = $this->listItems($prd['knowledge_areas'] ?? []);

        return <<<PROMPT
## Task PRD

**Projeto:** {$project->name}
**Task:** {$this->task->title}
**Objetivo:** {$objective}

**Critérios de Aceite:**
{$criteria}

**Restrições Técnicas:**
{$constraints}

**Áreas de Conhecimento:**
{$knowledge}

---

Decompona este PRD em Sub-PRDs atômicos conforme as instruções do sistema.
PROMPT;
    }

    private function listItems(array $items): string
    {
        if (empty($items)) {
            return '- (nenhum)';
        }

        return implode("\n", array_map(fn ($item) => "- {$item}", $items));
    }

    private function dispatchReadySubtasks(): void
    {
        $subtasks = $this->task->subtasks()
            ->where('status', SubtaskStatus::Pending)
            ->orderBy('execution_order')
            ->get();

        foreach ($subtasks as $subtask) {
            if ($subtask->areDependenciesMet() && ! $subtask->hasFileLockConflict()) {
                SubagentJob::dispatch($subtask);
            }
        }
    }

    private function failTask(string $reason): void
    {
        $this->task->update(['error_log' => $reason]);

        if ($this->task->canRetry()) {
            $this->task->increment('retry_count');
            $this->task->transitionTo(TaskStatus::Rollback, 'orchestrator', ['reason' => $reason]);
            $this->task->transitionTo(TaskStatus::Pending, 'orchestrator', ['reason' => 'retry']);
        } else {
            $this->task->transitionTo(TaskStatus::Rollback, 'orchestrator', ['reason' => $reason]);
            $this->task->transitionTo(TaskStatus::Failed, 'orchestrator', ['reason' => 'max retries exceeded']);
        }
    }
}
