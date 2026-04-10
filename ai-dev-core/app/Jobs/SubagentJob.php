<?php

namespace App\Jobs;

use App\Enums\SubtaskStatus;
use App\Enums\TaskStatus;
use App\Models\AgentConfig;
use App\Models\Subtask;
use App\Services\LLMGateway;
use App\Services\PromptFactory;
use App\Tools\ToolRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SubagentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    private const MAX_TOOL_ROUNDS = 20;

    public function __construct(
        public Subtask $subtask,
    ) {
        $this->queue = 'subagent';
    }

    public function handle(LLMGateway $gateway, PromptFactory $promptFactory, ToolRouter $toolRouter): void
    {
        Log::info("SubagentJob: Processing subtask {$this->subtask->id} - {$this->subtask->title}");

        $agent = AgentConfig::find($this->subtask->assigned_agent);
        if (! $agent || ! $agent->is_active) {
            $this->failSubtask("Agent '{$this->subtask->assigned_agent}' not found or inactive");
            return;
        }

        // Transition: pending → running
        $this->subtask->transitionTo(SubtaskStatus::Running, $agent->id);
        $this->subtask->update(['started_at' => now()]);

        $project = $this->subtask->task->project;
        $systemPrompt = $promptFactory->buildSystemPrompt($agent, $project);
        $userMessage = $promptFactory->buildSubagentMessage($this->subtask);

        $executionLog = [];
        $filesModified = [];
        $currentMessage = $userMessage;

        // Agentic loop: LLM → Tool calls → Results → LLM → ... until done
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $gateway->chat(
                agent: $agent,
                userMessage: $currentMessage,
                systemPrompt: $round === 0 ? $systemPrompt : null,
                project: $project,
                taskId: $this->subtask->task_id,
                subtaskId: $this->subtask->id,
            );

            if (! $response->success) {
                $this->failSubtask("LLM error (round {$round}): {$response->error}");
                return;
            }

            $executionLog[] = "--- Round {$round} ---";
            $executionLog[] = $response->content;

            // If no tool calls, the agent is done
            if (! $response->hasToolCalls()) {
                break;
            }

            // Execute tool calls and build results message
            $toolResults = [];
            foreach ($response->toolCalls as $toolCall) {
                $result = $toolRouter->dispatch($toolCall);
                $toolResults[] = [
                    'tool' => $toolCall['tool_name'] ?? 'unknown',
                    'action' => $toolCall['parameters']['action'] ?? $toolCall['params']['action'] ?? 'unknown',
                    'result' => $result->toArray(),
                ];

                $executionLog[] = "[Tool] {$toolCall['tool_name']}: " . ($result->success ? 'OK' : 'FAIL');

                // Track modified files
                if ($result->success && in_array($toolCall['tool_name'] ?? '', ['FileTool', 'GitTool'])) {
                    $path = $toolCall['parameters']['path'] ?? $toolCall['params']['path'] ?? null;
                    if ($path) {
                        $filesModified[] = $path;
                    }
                }
            }

            // Build next message with tool results
            $resultsJson = json_encode($toolResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $currentMessage = "Resultados das ferramentas:\n\n```json\n{$resultsJson}\n```\n\nContinue com as próximas ações ou finalize a tarefa.";
        }

        // Capture git diff for QA
        $workDir = $project->local_path;
        $diff = '';
        if ($workDir && is_dir("{$workDir}/.git")) {
            $diffResult = Process::path($workDir)->timeout(30)->run('git diff');
            $diff = $diffResult->output();
        }

        $this->subtask->update([
            'result_log' => mb_substr(implode("\n", $executionLog), 0, 65000),
            'result_diff' => mb_substr($diff, 0, 65000),
            'files_modified' => array_unique($filesModified),
        ]);

        // Transition: running → qa_audit
        $this->subtask->transitionTo(SubtaskStatus::QaAudit, $agent->id);

        // Dispatch QA audit
        QAAuditJob::dispatch($this->subtask);
    }

    private function failSubtask(string $reason): void
    {
        Log::error("SubagentJob: Subtask {$this->subtask->id} failed - {$reason}");

        $this->subtask->update([
            'result_log' => $reason,
            'completed_at' => now(),
        ]);

        $this->subtask->transitionTo(SubtaskStatus::Error, $this->subtask->assigned_agent, ['reason' => $reason]);

        // Check if parent task should be retried
        $this->checkParentTaskStatus();
    }

    private function checkParentTaskStatus(): void
    {
        $task = $this->subtask->task;
        $allSubtasks = $task->subtasks;

        $hasErrors = $allSubtasks->where('status', SubtaskStatus::Error)->isNotEmpty();
        $allDone = $allSubtasks->every(fn ($s) => in_array($s->status, [SubtaskStatus::Success, SubtaskStatus::Error]));

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
