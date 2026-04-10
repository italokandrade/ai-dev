<?php

namespace App\Jobs;

use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\AgentConfig;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\LLMGateway;
use App\Services\PromptFactory;
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

    public function handle(LLMGateway $gateway, PromptFactory $promptFactory): void
    {
        Log::info("OrchestratorJob: Processing task {$this->task->id} - {$this->task->title}");

        $agent = AgentConfig::find('orchestrator');
        if (! $agent || ! $agent->is_active) {
            $this->failTask('Orchestrator agent not found or inactive');
            return;
        }

        // Transition: pending → in_progress
        $this->task->transitionTo(TaskStatus::InProgress, 'orchestrator');
        $this->task->update(['started_at' => now(), 'assigned_agent_id' => $agent->id]);

        $project = $this->task->project;
        $systemPrompt = $promptFactory->buildSystemPrompt($agent, $project);
        $userMessage = $promptFactory->buildOrchestratorMessage($this->task);

        $response = $gateway->chat(
            agent: $agent,
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            project: $project,
            taskId: $this->task->id,
        );

        if (! $response->success) {
            $this->failTask("LLM error: {$response->error}");
            return;
        }

        // Parse Sub-PRDs from response
        $subPrds = $this->parseSubPrds($response->content);
        if (empty($subPrds)) {
            $this->failTask('Orchestrator returned no valid Sub-PRDs. Response: ' . mb_substr($response->content, 0, 500));
            return;
        }

        // Create subtasks
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
                'file_locks' => $subPrd['file_locks'] ?? $subPrd['files'] ?? null,
            ]);
            $order++;
        }

        Log::info("OrchestratorJob: Created {$order} subtasks for task {$this->task->id}");

        // Dispatch the first ready subtask(s)
        $this->dispatchReadySubtasks();
    }

    private function parseSubPrds(string $content): array
    {
        // Try to extract JSON array from the response
        if (preg_match('/```json\s*(\[.+?\])\s*```/s', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try raw JSON array
        if (preg_match('/\[.*\]/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
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
        Log::error("OrchestratorJob: Task {$this->task->id} failed - {$reason}");

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
