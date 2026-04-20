<?php

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Concerns\RemembersConversations;

class Task extends Model implements Conversational
{
    use Auditable, RemembersConversations, HasUuids;

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
            'prd_payload' => 'json',
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

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TaskTransition::class, 'entity_id')
            ->where('entity_type', 'task');
    }

    public function canTransitionTo(TaskStatus $newStatus): bool
    {
        return $this->status->canTransitionTo($newStatus);
    }

    public function transitionTo(TaskStatus $newStatus, string $agent = 'system', array $metadata = []): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException("Invalid transition from {$this->status->value} to {$newStatus->value}");
        }

        $oldStatus = $this->status;
        $this->update(['status' => $newStatus]);

        $this->transitions()->create([
            'entity_type' => 'task',
            'from_status' => $oldStatus->value,
            'to_status' => $newStatus->value,
            'triggered_by' => $agent,
            'metadata' => $metadata,
        ]);
    }

    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries;
    }

    public function isCompleted(): bool
    {
        return $this->status === TaskStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === TaskStatus::Failed;
    }
}
