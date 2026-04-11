<?php

namespace App\Models;

use App\Enums\AgentProvider;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'github_repo',
        'local_path',
        'gemini_session_id',
        'claude_session_id',
        'default_provider',
        'default_model',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'default_provider' => AgentProvider::class,
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class);
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProjectSpecification::class);
    }

    public function currentSpecification(): HasOne
    {
        return $this->hasOne(ProjectSpecification::class)
            ->orderByDesc('version')
            ->orderByDesc('created_at');
    }

    public function activeTasks(): HasMany
    {
        return $this->tasks()->whereNotIn('status', ['completed', 'failed']);
    }

    public function overallProgress(): float
    {
        $modules = $this->modules;

        if ($modules->isEmpty()) {
            return 0;
        }

        return round($modules->avg('progress_percentage'), 1);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(ProjectQuotation::class);
    }

    public function activeQuotation(): HasOne
    {
        return $this->hasOne(ProjectQuotation::class)
            ->whereIn('status', ['approved', 'in_progress'])
            ->orderByDesc('created_at');
    }

    /**
     * Acumula custos reais de tokens/infra na cotação ativa do projeto.
     */
    public function addExecutionCost(float $tokenCostUsd, float $infraCostBrl = 0): void
    {
        $quotation = $this->activeQuotation;
        if (! $quotation) {
            return;
        }

        $quotation->increment('actual_token_cost_usd', $tokenCostUsd);
        if ($infraCostBrl > 0) {
            $quotation->increment('actual_infra_cost', $infraCostBrl);
        }
        $quotation->recalculate();
        $quotation->save();
    }

    public function isActive(): bool
    {
        return $this->status === ProjectStatus::Active;
    }
}
