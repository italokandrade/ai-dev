<?php

namespace App\Models;

use App\Enums\SubtaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subtask extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id',
        'title',
        'sub_prd_payload',
        'status',
        'assigned_agent',
        'dependencies',
        'execution_order',
        'result_log',
        'result_diff',
        'files_modified',
        'file_locks',
        'retry_count',
        'max_retries',
        'qa_feedback',
        'commit_hash',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubtaskStatus::class,
            'sub_prd_payload' => 'array',
            'dependencies' => 'array',
            'files_modified' => 'array',
            'file_locks' => 'array',
            'execution_order' => 'integer',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TaskTransition::class, 'entity_id')
            ->where('entity_type', 'subtask')
            ->orderBy('created_at');
    }

    public function transitionTo(SubtaskStatus $newStatus, string $triggeredBy, ?array $metadata = null): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Transição inválida: {$this->status->value} → {$newStatus->value}"
            );
        }

        $oldStatus = $this->status;

        $this->update(['status' => $newStatus]);

        TaskTransition::create([
            'entity_type' => 'subtask',
            'entity_id' => $this->id,
            'from_status' => $oldStatus->value,
            'to_status' => $newStatus->value,
            'triggered_by' => $triggeredBy,
            'metadata' => $metadata,
        ]);
    }

    public function areDependenciesMet(): bool
    {
        if (empty($this->dependencies)) {
            return true;
        }

        return Subtask::whereIn('id', $this->dependencies)
            ->where('status', '!=', SubtaskStatus::Success->value)
            ->doesntExist();
    }

    public function hasFileLockConflict(): bool
    {
        if (empty($this->file_locks)) {
            return false;
        }

        return Subtask::where('id', '!=', $this->id)
            ->where('status', SubtaskStatus::Running->value)
            ->whereNotNull('file_locks')
            ->get()
            ->contains(function (Subtask $other) {
                return ! empty(array_intersect($this->file_locks, $other->file_locks ?? []));
            });
    }
}
