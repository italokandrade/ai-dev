<?php

namespace App\Models;

use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Services\StandardProjectModuleService;
use App\Support\PlanningLimits;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model implements Conversational
{
    use HasUuids, LogsActivity, RemembersConversations;

    public const array TARGET_SCAFFOLD_REQUIRED_FILES = [
        'artisan',
        'composer.json',
        '.mcp.json',
        'config/ai.php',
        'config/mcp.php',
    ];

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
        'blueprint_payload',
        'blueprint_approved_at',
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
            'blueprint_payload' => 'json',
            'blueprint_approved_at' => 'datetime',
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
            ->with([
                'tasks',
                'children' => fn ($q) => $q->with([
                    'tasks',
                    'children.tasks',
                ]),
            ])
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
        return once(function () {
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

            return 0.0;
        });
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

    public function isTargetScaffoldReady(): bool
    {
        return $this->targetScaffoldMissingReasons() === [];
    }

    /**
     * @return array<int, string>
     */
    public function targetScaffoldMissingReasons(): array
    {
        return self::targetScaffoldMissingReasonsForPath($this->local_path);
    }

    /**
     * @return array<int, string>
     */
    public static function targetScaffoldMissingReasonsForPath(?string $path): array
    {
        $path = trim((string) $path);

        if ($path === '') {
            return ['Caminho local do projeto não configurado.'];
        }

        if (! is_dir($path)) {
            return ["Diretório local não encontrado: {$path}"];
        }

        $missing = [];

        foreach (self::TARGET_SCAFFOLD_REQUIRED_FILES as $requiredFile) {
            $fullPath = $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $requiredFile);

            if (! is_file($fullPath)) {
                $missing[] = "Arquivo obrigatório ausente: {$requiredFile}";
            }
        }

        return $missing;
    }

    public function assertTargetScaffoldReady(string $action = 'continuar'): void
    {
        $missing = $this->targetScaffoldMissingReasons();

        if ($missing === []) {
            return;
        }

        throw new \RuntimeException(
            "Não é possível {$action}: scaffold do projeto alvo incompleto. ".implode('; ', $missing)
        );
    }

    public function isPrdApproved(): bool
    {
        return $this->prd_approved_at !== null;
    }

    public function isPrdReady(): bool
    {
        $prd = $this->prd_payload;

        return is_array($prd)
            && empty($prd['_status'] ?? null)
            && ! empty($prd['modules'] ?? []);
    }

    public function isPrdGenerating(): bool
    {
        return ($this->prd_payload['_status'] ?? null) === 'generating';
    }

    public function markPrdGenerationStarted(): void
    {
        $this->update([
            'prd_payload' => ['_status' => 'generating'],
            'prd_approved_at' => null,
            'blueprint_payload' => null,
            'blueprint_approved_at' => null,
        ]);
    }

    public function isBlueprintReady(): bool
    {
        $blueprint = $this->blueprint_payload;

        if (empty($blueprint) || ! is_array($blueprint) || ! empty($blueprint['_status'] ?? null)) {
            return false;
        }

        return ! empty($blueprint['domain_model']['entities'] ?? [])
            || ! empty($blueprint['use_cases'] ?? [])
            || ! empty($blueprint['workflows'] ?? [])
            || ! empty($blueprint['architecture']['components'] ?? []);
    }

    public function isBlueprintApproved(): bool
    {
        return $this->blueprint_approved_at !== null;
    }

    public function isBlueprintGenerating(): bool
    {
        return ($this->blueprint_payload['_status'] ?? null) === 'generating';
    }

    public function markBlueprintGenerationStarted(): void
    {
        $this->update([
            'blueprint_payload' => ['_status' => 'generating'],
            'blueprint_approved_at' => null,
        ]);
    }

    public function approvePrd(): void
    {
        if (! $this->isPrdReady()) {
            throw new \RuntimeException('O PRD Master precisa estar pronto antes da aprovação.');
        }

        $this->update(['prd_approved_at' => now()]);
    }

    public function approveBlueprint(): void
    {
        if (! $this->isBlueprintReady()) {
            throw new \RuntimeException('O Blueprint Técnico precisa estar pronto antes da criação dos módulos.');
        }

        $this->assertTargetScaffoldReady('aprovar o Blueprint');

        $this->update(['blueprint_approved_at' => now()]);
    }

    /**
     * Cria módulos de alto nível a partir do PRD payload.
     * Submódulos são criados posteriormente via PRD de cada módulo.
     */
    public function createModulesFromPrd(): void
    {
        $this->assertTargetScaffoldReady('criar módulos do projeto');

        $standardModules = app(StandardProjectModuleService::class);
        $standardModules->syncProject($this);

        $prd = is_array($this->prd_payload)
            ? $standardModules->mergeIntoProjectPrd($this->prd_payload)
            : [];

        if ($prd !== $this->prd_payload) {
            $this->forceFill(['prd_payload' => $prd])->save();
        }

        if (empty($prd['modules'])) {
            return;
        }

        $moduleIdMap = [];
        $existingRootModules = $this->modules()
            ->whereNull('parent_id')
            ->get()
            ->keyBy(fn (ProjectModule $module): string => $this->normalizeModuleName($module->name));

        foreach ($existingRootModules as $normalizedName => $module) {
            $moduleIdMap[$normalizedName] = $module->id;
        }

        $rootModuleLimit = PlanningLimits::rootModulesPerProject();
        $availableSlots = $rootModuleLimit === null
            ? PHP_INT_MAX
            : max(0, $rootModuleLimit - $existingRootModules->count());

        $modules = $standardModules->businessModulesFromPrd($prd)
            ->map(function (array $moduleData): array {
                $name = $this->stringValue($moduleData['name'] ?? '');

                return [
                    'name' => $name,
                    'normalized_name' => $this->normalizeModuleName($name),
                    'description' => $this->stringValue($moduleData['description'] ?? ''),
                    'priority' => $this->stringValue($moduleData['priority'] ?? 'normal'),
                    'dependencies' => $moduleData['dependencies'] ?? [],
                ];
            })
            ->filter(fn (array $moduleData): bool => $moduleData['name'] !== '' && $moduleData['normalized_name'] !== '')
            ->unique('normalized_name')
            ->values();

        foreach ($modules as $moduleData) {
            if ($existingRootModules->has($moduleData['normalized_name'])) {
                continue;
            }

            if ($availableSlots <= 0) {
                continue;
            }

            $priorityEnum = match ($moduleData['priority']) {
                'high' => Priority::High,
                'medium' => Priority::Medium,
                default => Priority::Normal,
            };

            $module = ProjectModule::create([
                'project_id' => $this->id,
                'name' => $moduleData['name'],
                'description' => $moduleData['description'],
                'status' => ModuleStatus::Planned,
                'priority' => $priorityEnum,
                'dependencies' => null,
            ]);

            $moduleIdMap[$moduleData['normalized_name']] = $module->id;
            $availableSlots--;
        }

        $standardDependencyIds = $standardModules->standardRootModuleIds($this);

        // Resolver dependências entre módulos
        foreach ($modules as $moduleData) {
            if (! isset($moduleIdMap[$moduleData['normalized_name']])) {
                continue;
            }

            $depIds = collect($moduleData['dependencies'])
                ->map(fn ($depName) => $moduleIdMap[$this->normalizeModuleName($this->stringValue($depName))] ?? null)
                ->merge($standardDependencyIds)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($depIds)) {
                ProjectModule::where('id', $moduleIdMap[$moduleData['normalized_name']])
                    ->update(['dependencies' => $depIds]);
            }
        }
    }

    private function normalizeModuleName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->stringValue(...), $value)));
        }

        return trim((string) $value);
    }
}
