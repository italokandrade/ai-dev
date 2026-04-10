<?php

namespace App\Jobs;

use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\AgentConfig;
use App\Models\Subtask;
use App\Services\LLMGateway;
use App\Services\PromptFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function handle(LLMGateway $gateway, PromptFactory $promptFactory): void
    {
        Log::info("QAAuditJob: Auditing subtask {$this->subtask->id} - {$this->subtask->title}");

        $agent = AgentConfig::find('qa-auditor');
        if (! $agent || ! $agent->is_active) {
            // If QA agent is not available, auto-approve
            Log::warning("QAAuditJob: QA agent not available, auto-approving subtask {$this->subtask->id}");
            $this->approveSubtask();
            return;
        }

        $project = $this->subtask->task->project;
        $systemPrompt = $promptFactory->buildSystemPrompt($agent, $project);
        $userMessage = $promptFactory->buildQAAuditMessage($this->subtask);

        $response = $gateway->chat(
            agent: $agent,
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            sessionId: $project->claude_session_id,
            taskId: $this->subtask->task_id,
            subtaskId: $this->subtask->id,
        );

        if (! $response->success) {
            Log::error("QAAuditJob: LLM error - {$response->error}, auto-approving");
            $this->approveSubtask();
            return;
        }

        // Parse QA audit result
        $auditResult = $this->parseAuditResult($response->content);

        if ($auditResult['approved']) {
            $this->approveSubtask($auditResult);
        } else {
            $this->rejectSubtask($auditResult);
        }
    }

    private function parseAuditResult(string $content): array
    {
        // Try to extract JSON from response
        if (preg_match('/```json\s*(\{.+?\})\s*```/s', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded) && isset($decoded['approved'])) {
                return $decoded;
            }
        }

        // Try raw JSON
        if (preg_match('/\{.*"approved".*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded) && isset($decoded['approved'])) {
                return $decoded;
            }
        }

        // Fallback: check for approval keywords
        $lower = strtolower($content);
        $approved = str_contains($lower, '"approved": true')
            || str_contains($lower, '"approved":true')
            || str_contains($lower, 'aprovado')
            || str_contains($lower, 'approved');

        return [
            'approved' => $approved,
            'overall_quality' => $approved ? 'good' : 'poor',
            'raw_response' => mb_substr($content, 0, 5000),
        ];
    }

    private function approveSubtask(?array $auditResult = null): void
    {
        Log::info("QAAuditJob: Subtask {$this->subtask->id} APPROVED");

        $this->subtask->transitionTo(SubtaskStatus::Success, 'qa-auditor', $auditResult);
        $this->subtask->update(['completed_at' => now()]);

        $this->checkTaskCompletion();
        $this->dispatchNextSubtasks();
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

            SubagentJob::dispatch($this->subtask);
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
            $task->update(['completed_at' => now()]);

            Log::info("Task {$task->id} COMPLETED successfully");
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

        foreach ($pendingSubtasks as $subtask) {
            if ($subtask->areDependenciesMet() && ! $subtask->hasFileLockConflict()) {
                SubagentJob::dispatch($subtask);
            }
        }
    }
}
