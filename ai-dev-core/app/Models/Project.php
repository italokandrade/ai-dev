<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Concerns\RemembersConversations;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Project extends Model implements Conversational
{
    use HasUuids, RemembersConversations, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Projeto {$eventName}");
    }

    protected $fillable = [
        'name',
        'description',
        'github_repo',
        'local_path',
        'status',
        'prd_payload',
        'prd_approved_at',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(ProjectFeature::class);
    }

    public function backendFeatures(): HasMany
    {
        return $this->hasMany(ProjectFeature::class)->where('type', 'backend');
    }

    public function frontendFeatures(): HasMany
    {
        return $this->hasMany(ProjectFeature::class)->where('type', 'frontend');
    }

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'prd_payload' => 'json',
            'prd_approved_at' => 'datetime',
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

    public function rootModules(): HasMany
    {
        return $this->hasMany(ProjectModule::class)
            ->whereNull('parent_id')
            ->with(['children.tasks'])
            ->orderBy('created_at');
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
        $totalTasks = $this->tasks()->count();
        if ($totalTasks > 0) {
            $completedTasks = $this->tasks()->where('status', 'completed')->count();
            return round(($completedTasks / $totalTasks) * 100, 1);
        }
        $totalModules = $this->modules()->count();
        if ($totalModules > 0) {
            $completedModules = $this->modules()->where('status', 'completed')->count();
            return round(($completedModules / $totalModules) * 100, 1);
        }
        return 0;
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

    public function addExecutionCost(float $tokenCostUsd, float $infraCostBrl = 0): void
    {
        $quotation = $this->activeQuotation;
        if (! $quotation) return;
        $quotation->increment('actual_token_cost_usd', $tokenCostUsd);
        if ($infraCostBrl > 0) $quotation->increment('actual_infra_cost', $infraCostBrl);
        $quotation->recalculate();
        $quotation->save();
    }

    public function isActive(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function isPrdApproved(): bool
    {
        return $this->prd_approved_at !== null;
    }

    public function approvePrd(): void
    {
        $this->update(['prd_approved_at' => now()]);
    }

    /**
     * Cria módulos e submódulos a partir do PRD payload.
     */
    public function createModulesFromPrd(): void
    {
        $prd = $this->prd_payload;

        if (empty($prd['modules'])) {
            return;
        }

        $moduleIdMap = [];

        foreach ($prd['modules'] as $moduleData) {
            $priorityEnum = match ($moduleData['priority'] ?? 'normal') {
                'high' => \App\Enums\Priority::High,
                'medium' => \App\Enums\Priority::Medium,
                default => \App\Enums\Priority::Normal,
            };

            $parentModule = ProjectModule::create([
                'project_id' => $this->id,
                'name' => $moduleData['name'],
                'description' => $moduleData['description'] ?? '',
                'status' => \App\Enums\ModuleStatus::Planned,
                'priority' => $priorityEnum,
                'dependencies' => null,
            ]);

            $moduleIdMap[$moduleData['name']] = $parentModule->id;

            if (! empty($moduleData['submodules'])) {
                foreach ($moduleData['submodules'] as $submoduleData) {
                    $subPriorityEnum = match ($submoduleData['priority'] ?? 'normal') {
                        'high' => \App\Enums\Priority::High,
                        'medium' => \App\Enums\Priority::Medium,
                        default => \App\Enums\Priority::Normal,
                    };

                    $subModule = ProjectModule::create([
                        'project_id' => $this->id,
                        'parent_id' => $parentModule->id,
                        'name' => $submoduleData['name'],
                        'description' => $submoduleData['description'] ?? '',
                        'status' => \App\Enums\ModuleStatus::Planned,
                        'priority' => $subPriorityEnum,
                        'dependencies' => null,
                    ]);

                    $moduleIdMap[$submoduleData['name']] = $subModule->id;
                }
            }
        }

        // Resolver dependências
        foreach ($prd['modules'] as $moduleData) {
            if (! empty($moduleData['dependencies'])) {
                $depIds = collect($moduleData['dependencies'])
                    ->map(fn ($depName) => $moduleIdMap[$depName] ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($depIds) && isset($moduleIdMap[$moduleData['name']])) {
                    ProjectModule::where('id', $moduleIdMap[$moduleData['name']])
                        ->update(['dependencies' => $depIds]);
                }
            }

            if (! empty($moduleData['submodules'])) {
                foreach ($moduleData['submodules'] as $submoduleData) {
                    if (! empty($submoduleData['dependencies'])) {
                        $depIds = collect($submoduleData['dependencies'])
                            ->map(fn ($depName) => $moduleIdMap[$depName] ?? null)
                            ->filter()
                            ->values()
                            ->all();

                        if (! empty($depIds) && isset($moduleIdMap[$submoduleData['name']])) {
                            ProjectModule::where('id', $moduleIdMap[$submoduleData['name']])
                                ->update(['dependencies' => $depIds]);
                        }
                    }
                }
            }
        }
    }
}
