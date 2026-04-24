<?php

namespace App\Jobs;

use App\Ai\Agents\ModulePrdAgent;
use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiRuntimeConfigService;
use App\Services\ProjectBlueprintService;
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

    private const int MAX_SUBMODULE_DEPTH = 1;

    private const int MAX_SUBMODULES_PER_MODULE = 8;

    private const int MAX_MODULES_PER_PROJECT = 120;

    private const int MAX_TASKS_PER_MODULE = 30;

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
        $this->module->refresh();

        // Se já tem PRD válido, pula geração e vai direto para auto-aprovação
        $existingPrd = $this->module->prd_payload;
        if (! empty($existingPrd) && empty($existingPrd['_status'] ?? '')) {
            Log::info("CascadeModulePrdJob: PRD já existe para '{$this->module->name}', pulando geração.");
            $blueprintService->mergeModulePrd($this->module->fresh(['project', 'parent']), $existingPrd);
            $this->module->refresh();
            $this->autoApprove($existingPrd);

            return;
        }

        Log::info("CascadeModulePrdJob: Gerando PRD para '{$this->module->name}'");

        $prdPayload = $this->generatePrd();

        $this->module->update(['prd_payload' => $prdPayload]);

        if (! empty($prdPayload['_status'])) {
            Log::error("CascadeModulePrdJob: PRD falhou para '{$this->module->name}', cascata interrompida neste ramo.");

            return;
        }

        $blueprintService->mergeModulePrd($this->module->fresh(['project', 'parent']), $prdPayload);
        $this->module->refresh();
        $this->autoApprove($prdPayload);
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

            return $this->fallbackPrd($e->getMessage());
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
        return (bool) ($prd['needs_submodules'] ?? false)
            && ! empty($prd['submodules'])
            && $this->moduleDepth() < self::MAX_SUBMODULE_DEPTH;
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
        $remainingProjectSlots = max(0, self::MAX_MODULES_PER_PROJECT - $this->module->project->modules()->count());

        foreach ($prd['submodules'] as $submoduleData) {
            if (count($created) >= self::MAX_SUBMODULES_PER_MODULE || count($created) >= $remainingProjectSlots) {
                break;
            }

            if (! is_array($submoduleData)) {
                continue;
            }

            $priorityEnum = match ($submoduleData['priority'] ?? 'normal') {
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
        // Se já tem tasks, não duplicar
        if ($this->module->tasks()->exists()) {
            Log::info("CascadeModulePrdJob: Tasks já existem para '{$this->module->name}'. Nada a fazer.");

            return;
        }

        $tasks = [];

        foreach ($prd['components'] ?? [] as $component) {
            if (! is_array($component)) {
                continue;
            }

            $componentType = $this->stringValue($component['type'] ?? 'Componente');
            $componentName = $this->stringValue($component['name'] ?? '');

            $this->pushTask($tasks, [
                'title' => "Implementar {$componentType}: {$componentName}",
                'description' => $component['description'] ?? '',
                'priority' => Priority::High,
            ]);
        }

        foreach ($prd['workflows'] ?? [] as $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            $steps = collect($workflow['steps'] ?? [])
                ->map(fn ($s) => is_array($s) ? ($s['name'] ?? json_encode($s)) : $s)
                ->implode(' → ');
            $workflowName = $this->stringValue($workflow['name'] ?? 'Fluxo');
            $this->pushTask($tasks, [
                'title' => "Fluxo: {$workflowName}",
                'description' => 'Steps: '.$steps,
                'priority' => Priority::High,
            ]);
        }

        foreach ($prd['api_endpoints'] ?? [] as $api) {
            if (! is_array($api)) {
                continue;
            }

            $method = $this->stringValue($api['method'] ?? 'GET');
            $uri = $this->stringValue($api['uri'] ?? '/');
            $this->pushTask($tasks, [
                'title' => "API {$method} {$uri}",
                'description' => $api['description'] ?? '',
                'priority' => Priority::Medium,
            ]);
        }

        foreach ($prd['database_schema']['tables'] ?? [] as $table) {
            if (! is_array($table)) {
                continue;
            }

            $tableName = $this->stringValue($table['name'] ?? '');
            $this->pushTask($tasks, [
                'title' => "Migration: {$tableName}",
                'description' => $table['description'] ?? '',
                'priority' => Priority::High,
            ]);
        }

        foreach ($prd['acceptance_criteria'] ?? [] as $criteria) {
            $criteriaText = is_array($criteria) ? json_encode($criteria, JSON_UNESCAPED_UNICODE) : (string) $criteria;
            $this->pushTask($tasks, [
                'title' => 'Teste: '.(is_array($criteria) ? json_encode($criteria) : $criteria),
                'description' => $criteriaText,
                'priority' => Priority::Medium,
            ]);
        }

        if ($tasks === [] && ! empty($prd['submodules'])) {
            foreach ($prd['submodules'] as $submodule) {
                if (! is_array($submodule)) {
                    continue;
                }

                $name = $this->stringValue($submodule['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $this->pushTask($tasks, [
                    'title' => "Implementar submódulo: {$name}",
                    'description' => $this->stringValue($submodule['description'] ?? ''),
                    'priority' => Priority::High,
                ]);
            }
        }

        foreach ($tasks as $taskData) {
            Task::create([
                'project_id' => $this->module->project_id,
                'module_id' => $this->module->id,
                'title' => $taskData['title'],
                'status' => TaskStatus::Pending,
                'priority' => $taskData['priority'],
                'source' => TaskSource::Specification,
                'prd_payload' => $this->taskPrdPayload($taskData, $prd),
                'max_retries' => 3,
            ]);
        }

        Log::info('CascadeModulePrdJob: '.count($tasks)." tasks criadas para '{$this->module->name}'");
    }

    /**
     * @param  array<int, array{title: string, description: mixed, priority: Priority}>  $tasks
     * @param  array{title: string, description: mixed, priority: Priority}  $task
     */
    private function pushTask(array &$tasks, array $task): void
    {
        if (count($tasks) >= self::MAX_TASKS_PER_MODULE) {
            return;
        }

        $title = $this->stringValue($task['title']);
        $normalizedTitle = $this->normalizeName($title);

        if ($title === '' || collect($tasks)->contains(fn (array $existing): bool => $this->normalizeName($existing['title']) === $normalizedTitle)) {
            return;
        }

        $tasks[] = [
            'title' => $title,
            'description' => $this->stringValue($task['description'] ?? ''),
            'priority' => $task['priority'],
        ];
    }

    /**
     * @param  array{title: string, description: string, priority: Priority}  $taskData
     * @return array<string, mixed>
     */
    private function taskPrdPayload(array $taskData, array $modulePrd): array
    {
        return [
            'objective' => $taskData['description'] !== '' ? $taskData['description'] : $taskData['title'],
            'acceptance_criteria' => $modulePrd['acceptance_criteria'] ?? [],
            'constraints' => [
                'Usar a stack TALL + Filament v5 definida pelo projeto alvo.',
                'Consultar Boost do projeto alvo antes de implementar.',
            ],
            'knowledge_areas' => ['laravel', 'filament', 'livewire', 'tailwind'],
            'module_context' => [
                'module_id' => $this->module->id,
                'module_name' => $this->module->name,
                'module_prd_title' => $modulePrd['title'] ?? null,
            ],
            'blueprint_context' => [
                'module_blueprint' => $this->module->blueprint_payload,
            ],
        ];
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

---
INSTRUÇÃO: Gere o PRD Técnico deste {$typeLabel}.
Este PRD será usado por desenvolvedores para implementar o código.
Especifique: tabelas de banco, APIs, componentes, regras de negócio, validações, permissões e fluxos.
Atualize também o `blueprint_contribution` com entidades, campos, relacionamentos, casos de uso, workflows e componentes que este {$typeLabel} adiciona ou detalha.
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
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $data = json_decode(trim($clean), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('CascadeModulePrdJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 300),
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('JSON inválido retornado pela IA: '.json_last_error_msg());
        }

        return $data;
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
    }
}
