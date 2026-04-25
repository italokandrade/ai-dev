<?php

namespace App\Jobs;

use App\Ai\Agents\ModulePrdAgent;
use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiRuntimeConfigService;
use App\Services\ModuleTaskPlannerService;
use App\Services\ProjectBlueprintService;
use App\Services\ProjectPlanningScopeService;
use App\Services\StandardProjectModuleService;
use App\Support\AiJson;
use App\Support\PlanningLimits;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CascadeModulePrdJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 660;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->module->id;
    }

    public function __construct(
        public ProjectModule $module,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(ProjectBlueprintService $blueprintService): void
    {
        $this->module->refresh()->loadMissing(['project', 'parent']);

        if ($this->isStandardModule()) {
            Log::info("CascadeModulePrdJob: '{$this->module->name}' é módulo padrão do AI-Dev. Tasks e submódulos não serão gerados.");
            SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
            $this->scheduleReconciliation();

            return;
        }

        // Se já tem PRD válido, pula geração e vai direto para auto-aprovação
        $existingPrd = $this->module->prd_payload;
        if (! empty($existingPrd) && empty($existingPrd['_status'] ?? '')) {
            $existingPrd = $this->normalizePrdForModuleRole($existingPrd);
            if ($existingPrd !== $this->module->prd_payload) {
                $this->module->update(['prd_payload' => $existingPrd]);
                $this->module->refresh();
            }

            Log::info("CascadeModulePrdJob: PRD já existe para '{$this->module->name}', pulando geração.");
            $blueprintService->mergeModulePrd($this->module->fresh(['project', 'parent']), $existingPrd);
            $this->module->refresh();
            $this->autoApprove($existingPrd);
            SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
            $this->scheduleReconciliation();

            return;
        }

        Log::info("CascadeModulePrdJob: Gerando PRD para '{$this->module->name}'");

        $prdPayload = $this->normalizePrdForModuleRole($this->generatePrd());

        $this->module->update(['prd_payload' => $prdPayload]);

        if (! empty($prdPayload['_status'])) {
            Log::error("CascadeModulePrdJob: PRD falhou para '{$this->module->name}', cascata interrompida neste ramo.");
            SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
            $this->scheduleReconciliation();

            return;
        }

        $blueprintService->mergeModulePrd($this->module->fresh(['project', 'parent']), $prdPayload);
        $this->module->refresh();
        $this->autoApprove($prdPayload);
        SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
        $this->scheduleReconciliation();
    }

    private function generatePrd(): array
    {
        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new ModulePrdAgent)
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $prdPayload = $this->parsePrd((string) $response);

            Log::info("CascadeModulePrdJob: PRD gerado com sucesso para '{$this->module->name}'");

            return $prdPayload;
        } catch (\Throwable $e) {
            Log::error('CascadeModulePrdJob: Falha na geração do PRD', [
                'module' => $this->module->name,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function autoApprove(array $prd): void
    {
        if ($this->shouldCreateSubmodules($prd)) {
            $this->createSubmodules($prd);
        } else {
            $this->createTasks($prd);
        }
    }

    private function shouldCreateSubmodules(array $prd): bool
    {
        $maxDepth = PlanningLimits::submoduleDepth();
        $submoduleLimit = app(ProjectPlanningScopeService::class)->submoduleLimit($this->module->project);

        return (bool) ($prd['needs_submodules'] ?? false)
            && ! empty($prd['submodules'])
            && $submoduleLimit !== 0
            && ($maxDepth === null || $this->moduleDepth() < $maxDepth);
    }

    private function createSubmodules(array $prd): void
    {
        if (empty($prd['submodules'])) {
            return;
        }

        // Se já tem filhos criados, apenas despacha cascata para eles
        $existingChildren = $this->module->children()->get();
        if ($existingChildren->isNotEmpty()) {
            Log::info("CascadeModulePrdJob: Submódulos já existem para '{$this->module->name}'. Despachando cascata para os existentes.");
            foreach ($existingChildren as $child) {
                self::dispatch($child);
            }

            return;
        }

        $created = [];
        $existingNames = $this->module->children()
            ->pluck('name')
            ->map(fn (string $name): string => $this->normalizeName($name))
            ->all();
        $seenNames = array_fill_keys($existingNames, true);
        $moduleLimit = PlanningLimits::modulesPerProject();
        $remainingProjectSlots = $moduleLimit === null
            ? PHP_INT_MAX
            : max(0, $moduleLimit - $this->module->project->modules()->count());
        $submoduleLimit = app(ProjectPlanningScopeService::class)->submoduleLimit($this->module->project);

        foreach ($prd['submodules'] as $submoduleData) {
            if (($submoduleLimit !== null && count($created) >= $submoduleLimit) || count($created) >= $remainingProjectSlots) {
                break;
            }

            if (! is_array($submoduleData)) {
                continue;
            }

            $priority = $this->stringValue($submoduleData['priority'] ?? 'normal');
            $priorityEnum = match ($priority) {
                'high' => Priority::High,
                'medium' => Priority::Medium,
                default => Priority::Normal,
            };

            $name = $this->stringValue($submoduleData['name'] ?? '');
            $normalizedName = $this->normalizeName($name);
            if ($name === '' || isset($seenNames[$normalizedName])) {
                continue;
            }

            $description = $this->stringValue($submoduleData['description'] ?? '');

            $submodule = ProjectModule::create([
                'project_id' => $this->module->project_id,
                'parent_id' => $this->module->id,
                'name' => $name,
                'description' => $description,
                'status' => ModuleStatus::Planned,
                'priority' => $priorityEnum,
            ]);

            $created[] = $submodule;
            $seenNames[$normalizedName] = true;
        }

        Log::info('CascadeModulePrdJob: '.count($created)." submódulos criados para '{$this->module->name}'. Despachando cascata.");

        foreach ($created as $submodule) {
            self::dispatch($submodule);
        }
    }

    private function createTasks(array $prd): void
    {
        if (PlanningLimits::deferTaskGenerationUntilProjectPrdsComplete() && $this->projectHasPendingPlanningPrds()) {
            Log::info("CascadeModulePrdJob: Tasks de '{$this->module->name}' adiadas até todos os PRDs de módulos/submódulos ficarem prontos.");
            $this->scheduleReconciliation();

            return;
        }

        // Se já tem tasks, não duplicar
        if ($this->module->tasks()->exists()) {
            Log::info("CascadeModulePrdJob: Tasks já existem para '{$this->module->name}'. Nada a fazer.");

            return;
        }

        $planner = app(ModuleTaskPlannerService::class);
        $tasks = $planner->taskDefinitions(
            $this->module,
            $prd,
            app(ProjectPlanningScopeService::class)->taskLimit($this->module->project),
        );

        foreach ($tasks as $taskData) {
            Task::create([
                'project_id' => $this->module->project_id,
                'module_id' => $this->module->id,
                'title' => $taskData['title'],
                'status' => TaskStatus::Pending,
                'priority' => $taskData['priority'],
                'source' => $taskData['source'],
                'prd_payload' => $planner->taskPrdPayload($this->module->fresh(['project']), $taskData, $prd),
                'max_retries' => 3,
            ]);
        }

        Log::info('CascadeModulePrdJob: '.count($tasks)." tasks criadas para '{$this->module->name}'");
    }

    private function buildPrompt(): string
    {
        $project = $this->module->project;
        $moduleName = $this->module->name;
        $moduleDescription = $this->module->description ?? 'Nenhuma descrição fornecida.';
        $isSubmodule = $this->module->parent_id !== null;
        $parentName = $isSubmodule ? ($this->module->parent?->name ?? 'Módulo Pai') : null;

        $projectDescription = $project->description ?? '';
        if (strlen($projectDescription) > 1000) {
            $projectDescription = substr($projectDescription, 0, 1000)."\n\n[...descrição truncada...]";
        }

        $typeLabel = $isSubmodule ? 'SUBMÓDULO' : 'MÓDULO';
        $parentInfo = $isSubmodule ? "\nMÓDULO PAI: {$parentName}\n" : '';
        $blueprintContext = $this->blueprintContext();
        $scopeGuidance = app(ProjectPlanningScopeService::class)->promptGuidance($project, "geracao do PRD de {$typeLabel}");

        return <<<PROMPT
PROJETO: {$project->name}

DESCRIÇÃO DO PROJETO:
{$projectDescription}

{$typeLabel}: {$moduleName}
{$parentInfo}
DESCRIÇÃO DO {$typeLabel}:
{$moduleDescription}

BLUEPRINT TÉCNICO GLOBAL ATUAL:
{$blueprintContext}

{$scopeGuidance}

---
INSTRUÇÃO: Gere o PRD Técnico deste {$typeLabel}.
Este PRD será usado por desenvolvedores para implementar o código.
Especifique: tabelas de banco, APIs, componentes, regras de negócio, validações, permissões e fluxos.
Atualize também o `blueprint_contribution` com entidades, campos, relacionamentos, casos de uso, workflows e componentes que este {$typeLabel} adiciona ou detalha.
Não crie novo módulo raiz. Não adicione capacidades fora do escopo deste {$typeLabel}.
Se este {$typeLabel} precisar de submódulos, limite-se a definir a fronteira e os submódulos; detalhes implementáveis ficam para os PRDs dos submódulos.
PROMPT;
    }

    private function blueprintContext(): string
    {
        $blueprint = $this->module->project->blueprint_payload;

        if (empty($blueprint) || ! is_array($blueprint)) {
            return 'Nenhum Blueprint Técnico Global aprovado ou gerado. Reutilize apenas o PRD Master e não invente entidades fora do escopo do módulo.';
        }

        $json = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (strlen((string) $json) > 10000) {
            return substr((string) $json, 0, 10000)."\n\n[...Blueprint truncado para otimização...]";
        }

        return (string) $json;
    }

    private function parsePrd(string $raw): array
    {
        try {
            return AiJson::object($raw, 'PRD de modulo em cascata');
        } catch (\Throwable $e) {
            Log::warning('CascadeModulePrdJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 300),
                'json_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function fallbackPrd(string $error): array
    {
        return [
            'title' => "{$this->module->name} — PRD Técnico",
            'objective' => 'Geração automática do PRD falhou. Por favor, revise os logs e tente novamente.',
            '_status' => 'ai_generation_failed',
            '_error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $prd
     * @return array<string, mixed>
     */
    private function normalizePrdForModuleRole(array $prd): array
    {
        $isSubmodule = $this->module->parent_id !== null;
        $submoduleLimit = app(ProjectPlanningScopeService::class)->submoduleLimit($this->module->project);

        if ($isSubmodule || $submoduleLimit === 0) {
            $prd['needs_submodules'] = false;
            $prd['submodules'] = [];

            return $prd;
        }

        if ($this->shouldCreateSubmodules($prd)) {
            $prd['submodules'] = collect($prd['submodules'])
                ->filter(fn (mixed $submodule): bool => is_array($submodule))
                ->map(fn (array $submodule): array => [
                    'name' => $this->stringValue($submodule['name'] ?? ''),
                    'description' => $this->stringValue($submodule['description'] ?? ''),
                    'priority' => $this->stringValue($submodule['priority'] ?? 'medium'),
                ])
                ->filter(fn (array $submodule): bool => $submodule['name'] !== '')
                ->unique(fn (array $submodule): string => $this->normalizeName($submodule['name']))
                ->take($submoduleLimit ?? PHP_INT_MAX)
                ->values()
                ->all();

            foreach (['database_schema', 'api_endpoints', 'components', 'acceptance_criteria'] as $implementationKey) {
                unset($prd[$implementationKey]);
            }

            $prd['planning_role'] = 'module_boundary';
        }

        return $prd;
    }

    private function projectHasPendingPlanningPrds(): bool
    {
        $project = $this->module->project->fresh(['modules']);

        if ($project === null) {
            return false;
        }

        return $project->modules
            ->reject(fn (ProjectModule $module): bool => $this->isStandardModuleInstance($module))
            ->contains(function (ProjectModule $module): bool {
                $prd = $module->prd_payload;

                return empty($prd) || ! empty($prd['_status'] ?? null);
            });
    }

    private function moduleDepth(): int
    {
        $depth = 0;
        $parent = $this->module->parent;

        while ($parent !== null) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    private function isStandardModule(): bool
    {
        return $this->isStandardModuleInstance($this->module);
    }

    private function isStandardModuleInstance(ProjectModule $module): bool
    {
        $prd = $module->prd_payload;

        return is_array($prd)
            && (
                ($prd['standard_module'] ?? false) === true
                || ($prd['source'] ?? null) === StandardProjectModuleService::SOURCE
            );
    }

    private function normalizeName(string $name): string
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

    private function scheduleReconciliation(): void
    {
        ReconcileProjectCascadeJob::dispatch($this->module->project->fresh())
            ->delay(now()->addMinutes(5));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CascadeModulePrdJob: Job falhou por completo', [
            'module' => $this->module->name,
            'error' => $exception->getMessage(),
        ]);

        // Só salva fallback se não houver PRD válido já salvo
        $this->module->refresh();
        $current = $this->module->prd_payload;
        if (empty($current) || ! empty($current['_status'] ?? '')) {
            $this->module->update([
                'prd_payload' => $this->fallbackPrd($exception->getMessage()),
            ]);
        }

        SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
        $this->scheduleReconciliation();
    }
}
