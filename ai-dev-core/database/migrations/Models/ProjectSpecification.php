<?php

namespace App\Models;

use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Jobs\GenerateTasksFromSpecJob;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSpecification extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'user_description',
        'ai_specification',
        'version',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'ai_specification' => 'array',
            'version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function approve(User $user): void
    {
        $this->update([
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $this->createModulesAndSubmodules();

        // After modules/submodules are created, dispatch tasks generation
        // which in turn dispatches quotation generation when done
        GenerateTasksFromSpecJob::dispatch($this);
    }

    private function createModulesAndSubmodules(): void
    {
        $aiSpec = $this->ai_specification;

        if (empty($aiSpec['modules'])) {
            return;
        }

        $moduleIdMap = [];

        foreach ($aiSpec['modules'] as $moduleData) {
            $priorityEnum = match ($moduleData['priority'] ?? 'normal') {
                'high' => Priority::High,
                'medium' => Priority::Medium,
                default => Priority::Normal,
            };

            $parentModule = ProjectModule::create([
                'project_id' => $this->project_id,
                'name' => $moduleData['name'],
                'description' => $moduleData['description'],
                'status' => ModuleStatus::Planned,
                'priority' => $priorityEnum,
                'dependencies' => null,
            ]);

            $moduleIdMap[$moduleData['name']] = $parentModule->id;

            // Cria submódulos se existirem
            if (! empty($moduleData['submodules'])) {
                foreach ($moduleData['submodules'] as $submoduleData) {
                    $subPriorityEnum = match ($submoduleData['priority'] ?? 'normal') {
                        'high' => Priority::High,
                        'medium' => Priority::Medium,
                        default => Priority::Normal,
                    };

                    $subModule = ProjectModule::create([
                        'project_id' => $this->project_id,
                        'parent_id' => $parentModule->id,
                        'name' => $submoduleData['name'],
                        'description' => $submoduleData['description'],
                        'status' => ModuleStatus::Planned,
                        'priority' => $subPriorityEnum,
                        'dependencies' => null,
                    ]);

                    $moduleIdMap[$submoduleData['name']] = $subModule->id;
                }
            }
        }

        // Resolver dependências para todos os módulos (pais e filhos)
        $resolveDependencies = function ($items) use (&$resolveDependencies, $moduleIdMap) {
            foreach ($items as $itemData) {
                if (! empty($itemData['dependencies'])) {
                    $depIds = collect($itemData['dependencies'])
                        ->map(fn ($depName) => $moduleIdMap[$depName] ?? null)
                        ->filter()
                        ->values()
                        ->all();

                    if (! empty($depIds) && isset($moduleIdMap[$itemData['name']])) {
                        ProjectModule::where('id', $moduleIdMap[$itemData['name']])
                            ->update(['dependencies' => $depIds]);
                    }
                }

                // Chamar recursivamente para os submódulos
                if (! empty($itemData['submodules'])) {
                    $resolveDependencies($itemData['submodules']);
                }
            }
        };

        $resolveDependencies($aiSpec['modules']);
    }
}
