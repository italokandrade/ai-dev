<?php

namespace App\Jobs;

use App\Ai\Agents\OrchestratorAgent;
use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Services\AiRuntimeConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        if (! SystemSetting::isDevelopmentEnabled()) {
            Log::info("OrchestratorJob: Development is globally disabled. Skipping task {$this->task->id}.");

            return;
        }

        Log::info("OrchestratorJob: Processing task {$this->task->id} — {$this->task->title}");

        // Transition: pending → in_progress
        $this->task->transitionTo(TaskStatus::InProgress, 'orchestrator');
        $this->task->update(['started_at' => now()]);

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = OrchestratorAgent::make()->prompt(
                $prompt,
                provider: $aiConfig['provider'],
                model: $aiConfig['model'],
            );
            $subPrds = $response->data;

            if (! is_array($subPrds) || empty($subPrds)) {
                throw new \RuntimeException('Orchestrator returned empty or invalid Sub-PRD array.');
            }
        } catch (\Throwable $e) {
            Log::error("OrchestratorJob: Failed for task {$this->task->id}", ['error' => $e->getMessage()]);
            $this->failTask("AI generation failed: {$e->getMessage()}");

            return;
        }

        // 1st pass: Create subtasks and map titles to UUIDs
        $titleToUuid = [];
        $createdData = [];
        $order = 1;

        foreach ($subPrds as $subPrd) {
            $subtask = Subtask::create([
                'task_id' => $this->task->id,
                'title' => $subPrd['title'] ?? "Subtask {$order}",
                'sub_prd_payload' => $subPrd,
                'status' => SubtaskStatus::Pending,
                'assigned_agent' => $subPrd['assigned_agent'] ?? 'backend-specialist',
                'dependencies' => null,
                'execution_order' => $subPrd['execution_order'] ?? $order,
                'file_locks' => $subPrd['files'] ?? null,
            ]);

            $titleToUuid[$subtask->title] = $subtask->id;
            $createdData[] = ['model' => $subtask, 'raw' => $subPrd];
            $order++;
        }

        Log::info('OrchestratorJob: Created '.count($subPrds)." subtasks for task {$this->task->id}");

        // 2nd pass: Update dependencies with actual UUIDs
        foreach ($createdData as $item) {
            $rawDeps = $item['raw']['dependencies'] ?? [];
            if (! empty($rawDeps)) {
                $uuidDeps = collect($rawDeps)->map(function ($depTitle) use ($titleToUuid) {
                    return $titleToUuid[$depTitle] ?? null;
                })->filter(fn ($id) => ! empty($id) && Str::isUuid($id))->values()->all();

                if (! empty($uuidDeps)) {
                    $item['model']->update(['dependencies' => $uuidDeps]);
                }
            }
        }

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
        $structuredContext = $this->structuredContext($prd);

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

**Contexto Estruturado:**
{$structuredContext}

---

Decompona este PRD em Sub-PRDs atômicos conforme as instruções do sistema.
PROMPT;
    }

    private function structuredContext(array $prd): string
    {
        $context = array_filter([
            'architecture_checkpoint' => $prd['architecture_checkpoint'] ?? null,
            'database_schema' => $prd['database_schema'] ?? null,
            'module_context' => $prd['module_context'] ?? null,
            'blueprint_context' => $prd['blueprint_context'] ?? null,
            'context' => $prd['context'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        if ($context === []) {
            return '{}';
        }

        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (strlen((string) $json) > 12000) {
            return substr((string) $json, 0, 12000)."\n\n[...contexto truncado...]";
        }

        return (string) $json;
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

        $reservedLocks = [];

        foreach ($subtasks as $subtask) {
            $locks = $subtask->file_locks ?? [];
            $hasReservedConflict = $locks !== [] && array_intersect($locks, $reservedLocks) !== [];

            if ($subtask->areDependenciesMet() && ! $subtask->hasFileLockConflict() && ! $hasReservedConflict) {
                ProcessSubtaskJob::dispatch($subtask);
                $reservedLocks = array_values(array_unique([...$reservedLocks, ...$locks]));
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
