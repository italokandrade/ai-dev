<?php

namespace App\Models;

use App\Enums\ModuleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ProjectModule extends Model
{
    use HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Módulo {$eventName}");
    }

    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'description',
        'status',
        'priority',
        'dependencies',
        'progress_percentage',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ModuleStatus::class,
            'priority' => \App\Enums\Priority::class,
            'dependencies' => 'array',
            'progress_percentage' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'module_id');
    }

    public function completedTasks(): HasMany
    {
        return $this->tasks()->where('status', 'completed');
    }

    public function activeTasks(): HasMany
    {
        return $this->tasks()->whereNotIn('status', ['completed', 'failed']);
    }

    /**
     * Recalcula o percentual de progresso baseado nas tasks concluídas.
     */
    public function recalculateProgress(): void
    {
        $total = $this->tasks()->count();

        if ($total === 0) {
            $this->update(['progress_percentage' => 0]);
            return;
        }

        $completed = $this->completedTasks()->count();
        $percentage = round(($completed / $total) * 100, 1);

        $this->update(['progress_percentage' => $percentage]);

        // Auto-transição: se 100% concluído, mover para testing
        if ($percentage === 100 && $this->status === ModuleStatus::InProgress) {
            $this->transitionTo(ModuleStatus::Testing);
        }
    }

    public function transitionTo(ModuleStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Transição inválida: {$this->status->value} → {$newStatus->value}"
            );
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === ModuleStatus::InProgress && ! $this->started_at) {
            $updates['started_at'] = now();
        }

        if ($newStatus === ModuleStatus::Completed) {
            $updates['completed_at'] = now();
        }

        if ($newStatus === ModuleStatus::Revision) {
            $updates['completed_at'] = null;
        }

        $this->update($updates);
    }

    /**
     * Verifica se todas as dependências deste módulo estão concluídas.
     */
    public function dependenciesMet(): bool
    {
        if (empty($this->dependencies)) {
            return true;
        }

        return self::whereIn('id', $this->dependencies)
            ->where('status', ModuleStatus::Completed)
            ->count() === count($this->dependencies);
    }
}
