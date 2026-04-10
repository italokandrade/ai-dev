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
            project: $project,
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
            // Stage all changes
            Process::path($workDir)->timeout(30)->run('git add -A');

            // Commit with descriptive message
            $subtaskTitle = addslashes($this->subtask->title);
            $message = "ai-dev: {$subtaskTitle} [subtask:{$this->subtask->id}]";
            $commitResult = Process::path($workDir)->timeout(30)
                ->run("git commit -m " . escapeshellarg($message) . " --allow-empty");

            if (! $commitResult->successful()) {
                Log::warning("QAAuditJob: git commit failed for subtask {$this->subtask->id}: {$commitResult->errorOutput()}");
                return null;
            }

            // Capture the commit hash
            $hashResult = Process::path($workDir)->timeout(10)->run('git rev-parse HEAD');
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

        foreach ($pendingSubtasks as $subtask) {
            if ($subtask->areDependenciesMet() && ! $subtask->hasFileLockConflict()) {
                SubagentJob::dispatch($subtask);
            }
        }
    }
}
