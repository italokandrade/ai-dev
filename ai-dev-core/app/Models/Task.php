<?php

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Traits\HasConversations;

class Task extends Model implements RemembersConversations
{
    use Auditable, HasConversations, HasUuids;

    protected $fillable = [
        'project_id',
        'module_id',
        'title',
        'prd_payload',
        'status',
        'priority',
        'assigned_agent_id',
        'git_branch',
        'commit_hash',
        'last_session_id',
        'retry_count',
        'max_retries',
        'error_log',
        'source',
        'is_redo',
        'original_task_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'source' => TaskSource::class,
            'prd_payload' => 'array',
            'priority' => \App\Enums\Priority::class,
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'is_redo' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ProjectModule::class, 'module_id');
    }

    public function originalTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_task_id');
    }

    public function redos(): HasMany
    {
        return $this->hasMany(self::class, 'original_task_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(AgentConfig::class, 'assigned_agent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('execution_order');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TaskTransition::class, 'entity_id')
            ->where('entity_type', 'task')
            ->orderBy('created_at');
    }

    public function transitionTo(TaskStatus $newStatus, string $triggeredBy, ?array $metadata = null): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Transição inválida: {$this->status->value} → {$newStatus->value}"
            );
        }

        $oldStatus = $this->status;

        $this->update(['status' => $newStatus]);

        TaskTransition::create([
            'entity_type' => 'task',
            'entity_id' => $this->id,
            'from_status' => $oldStatus->value,
            'to_status' => $newStatus->value,
            'triggered_by' => $triggeredBy,
            'metadata' => $metadata,
        ]);
    }

    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries;
    }

    /**
     * Refaz esta task (redo) em vez de criar uma nova.
     * Reseta o estado, limpa subtasks anteriores e re-dispatcha o Orchestrator.
     */
    public function redo(?array $updatedPrd = null): self
    {
        // Se a task original já completou ou falhou, cria um redo linkado
        if (in_array($this->status, [TaskStatus::Completed, TaskStatus::Failed])) {
            $redo = self::create([
                'project_id' => $this->project_id,
                'title' => $this->title,
                'prd_payload' => $updatedPrd ?? $this->prd_payload,
                'status' => TaskStatus::Pending,
                'priority' => $this->priority,
                'source' => $this->source,
                'max_retries' => $this->max_retries,
                'is_redo' => true,
                'original_task_id' => $this->original_task_id ?? $this->id,
            ]);

            TaskTransition::create([
                'entity_type' => 'task',
                'entity_id' => $redo->id,
                'from_status' => null,
                'to_status' => TaskStatus::Pending->value,
                'triggered_by' => 'redo',
                'metadata' => ['original_task_id' => $this->id],
            ]);

            return $redo;
        }

        return $this;
    }
}
