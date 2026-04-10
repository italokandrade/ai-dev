<?php

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'title',
        'prd_payload',
        'status',
        'priority',
        'assigned_agent_id',
        'git_branch',
        'last_session_id',
        'retry_count',
        'max_retries',
        'error_log',
        'source',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'source' => TaskSource::class,
            'prd_payload' => 'array',
            'priority' => 'integer',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
}
