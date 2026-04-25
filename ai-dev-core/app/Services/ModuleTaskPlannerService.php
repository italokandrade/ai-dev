<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\TaskSource;
use App\Models\ProjectModule;
use App\Support\PlanningLimits;

class ModuleTaskPlannerService
{
    public const string ARCHITECTURE_CHECKPOINT_TYPE = 'data_architecture_checkpoint';

    /**
     * @param  array<string, mixed>  $prd
     * @return array<int, array<string, mixed>>
     */
    public function taskDefinitions(ProjectModule $module, array $prd, ?int $limit = null): array
    {
        $limit ??= PlanningLimits::tasksPerModule();
        $limit = $limit !== null && $limit <= 0 ? null : $limit;

        $tasks = [];

        if ($this->requiresArchitectureCheckpoint($prd)) {
            $this->pushTask($tasks, [
                'type' => self::ARCHITECTURE_CHECKPOINT_TYPE,
                'title' => "Checkpoint de Arquitetura de Dados: {$module->name}",
                'description' => 'Validar migrations, Models, relacionamentos Eloquent, SQLite temporario e ERD/Mermaid antes de liberar interfaces ou APIs. Postgres entra somente apos aprovacao do orcamento e scaffold fisico.',
                'priority' => Priority::High,
                'source' => TaskSource::Architecture,
            ], $limit);
        }

        foreach ($prd['components'] ?? [] as $component) {
            if (! is_array($component)) {
                continue;
            }

            $componentType = $this->stringValue($component['type'] ?? 'Componente');
            $componentName = $this->stringValue($component['name'] ?? '');

            $this->pushTask($tasks, [
                'type' => 'component',
                'title' => "Implementar {$componentType}: {$componentName}",
                'description' => $component['description'] ?? '',
                'priority' => Priority::High,
                'source' => TaskSource::Prd,
            ], $limit);
        }

        foreach ($prd['workflows'] ?? [] as $workflow) {
            if (! is_array($workflow)) {
                continue;
            }

            $steps = collect($workflow['steps'] ?? [])
                ->map(fn ($step) => is_array($step) ? ($step['name'] ?? json_encode($step, JSON_UNESCAPED_UNICODE)) : $step)
                ->implode(' -> ');

            $workflowName = $this->stringValue($workflow['name'] ?? 'Fluxo');
            $this->pushTask($tasks, [
                'type' => 'workflow',
                'title' => "Fluxo: {$workflowName}",
                'description' => 'Steps: '.$steps,
                'priority' => Priority::High,
                'source' => TaskSource::Prd,
            ], $limit);
        }

        foreach ($prd['api_endpoints'] ?? [] as $api) {
            if (! is_array($api)) {
                continue;
            }

            $method = $this->stringValue($api['method'] ?? 'GET');
            $uri = $this->stringValue($api['uri'] ?? '/');

            $this->pushTask($tasks, [
                'type' => 'api',
                'title' => "API {$method} {$uri}",
                'description' => $api['description'] ?? '',
                'priority' => Priority::Medium,
                'source' => TaskSource::Prd,
            ], $limit);
        }

        if (! $this->requiresArchitectureCheckpoint($prd)) {
            foreach ($prd['database_schema']['tables'] ?? [] as $table) {
                if (! is_array($table)) {
                    continue;
                }

                $tableName = $this->stringValue($table['name'] ?? '');
                $this->pushTask($tasks, [
                    'type' => 'database_table',
                    'title' => "Migration: {$tableName}",
                    'description' => $table['description'] ?? '',
                    'priority' => Priority::High,
                    'source' => TaskSource::Prd,
                ], $limit);
            }
        }

        foreach ($prd['acceptance_criteria'] ?? [] as $criteria) {
            $criteriaText = is_array($criteria)
                ? json_encode($criteria, JSON_UNESCAPED_UNICODE)
                : (string) $criteria;

            $this->pushTask($tasks, [
                'type' => 'test',
                'title' => 'Teste: '.$criteriaText,
                'description' => "Garantir que o criterio de aceitacao seja atendido: {$criteriaText}",
                'priority' => Priority::Medium,
                'source' => TaskSource::Prd,
            ], $limit);
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
                    'type' => 'submodule',
                    'title' => "Implementar submodulo: {$name}",
                    'description' => $this->stringValue($submodule['description'] ?? ''),
                    'priority' => Priority::High,
                    'source' => TaskSource::Prd,
                ], $limit);
            }
        }

        if ($tasks === [] && empty($prd['submodules'])) {
            $this->pushTask($tasks, [
                'type' => 'module_implementation',
                'title' => "Implementar módulo: {$module->name}",
                'description' => $this->stringValue($prd['objective'] ?? $prd['scope'] ?? $module->description ?? ''),
                'priority' => Priority::High,
                'source' => TaskSource::Prd,
            ], $limit);
        }

        return $tasks;
    }

    /**
     * @param  array<string, mixed>  $taskData
     * @param  array<string, mixed>  $modulePrd
     * @return array<string, mixed>
     */
    public function taskPrdPayload(ProjectModule $module, array $taskData, array $modulePrd): array
    {
        $isArchitectureCheckpoint = ($taskData['type'] ?? null) === self::ARCHITECTURE_CHECKPOINT_TYPE;

        return [
            'type' => $taskData['type'] ?? 'implementation',
            'objective' => $taskData['description'] !== '' ? $taskData['description'] : $taskData['title'],
            'acceptance_criteria' => $this->acceptanceCriteria($modulePrd, $isArchitectureCheckpoint),
            'constraints' => $this->constraints($isArchitectureCheckpoint),
            'knowledge_areas' => $isArchitectureCheckpoint
                ? ['database', 'laravel', 'eloquent', 'mermaid', 'architecture']
                : ['laravel', 'filament', 'livewire', 'tailwind'],
            'module_context' => [
                'module_id' => $module->id,
                'module_name' => $module->name,
                'module_prd_title' => $modulePrd['title'] ?? null,
            ],
            'database_schema' => $modulePrd['database_schema'] ?? ['tables' => []],
            'architecture_checkpoint' => [
                'required' => $this->requiresArchitectureCheckpoint($modulePrd),
                'is_checkpoint_task' => $isArchitectureCheckpoint,
                'sqlite_database' => 'database/ai_dev_architecture.sqlite',
                'artifacts' => [
                    '.ai-dev/architecture/domain-model.mmd',
                    '.ai-dev/architecture/domain-model.md',
                    '.ai-dev/architecture/domain-model.json',
                    '.ai-dev/architecture/erd-physical.txt',
                ],
                'recommended_commands' => [
                    'php artisan migrate:fresh --force',
                    'php artisan generate:erd .ai-dev/architecture/erd-physical.txt',
                    'php artisan migrate:status',
                ],
            ],
            'blueprint_context' => [
                'module_blueprint' => $module->blueprint_payload,
                'project_domain_model' => $this->projectDomainModel($module),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $prd
     */
    public function requiresArchitectureCheckpoint(array $prd): bool
    {
        if (! empty($prd['database_schema']['tables'] ?? [])) {
            return true;
        }

        $contribution = $prd['blueprint_contribution']['domain_model'] ?? null;

        return is_array($contribution)
            && (
                ! empty($contribution['entities'] ?? [])
                || ! empty($contribution['relationships'] ?? [])
            );
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<string, mixed>  $task
     */
    private function pushTask(array &$tasks, array $task, ?int $limit): void
    {
        if ($limit !== null && count($tasks) >= $limit) {
            return;
        }

        $title = $this->stringValue($task['title'] ?? '');
        $normalizedTitle = $this->normalizeName($title);

        if ($title === '' || collect($tasks)->contains(fn (array $existing): bool => $this->normalizeName($existing['title'] ?? '') === $normalizedTitle)) {
            return;
        }

        $tasks[] = [
            'type' => $task['type'] ?? 'implementation',
            'title' => $title,
            'description' => $this->stringValue($task['description'] ?? ''),
            'priority' => $task['priority'] instanceof Priority ? $task['priority'] : Priority::Normal,
            'source' => $task['source'] instanceof TaskSource ? $task['source'] : TaskSource::Prd,
        ];
    }

    /**
     * @param  array<string, mixed>  $modulePrd
     * @return array<int, string>
     */
    private function acceptanceCriteria(array $modulePrd, bool $isArchitectureCheckpoint): array
    {
        $criteria = collect($modulePrd['acceptance_criteria'] ?? [])
            ->map(fn (mixed $criterion): string => $this->stringValue($criterion))
            ->filter()
            ->values()
            ->all();

        if (! $isArchitectureCheckpoint) {
            return [
                ...$criteria,
                'Antes de criar interfaces, APIs ou fluxos, confirmar que o checkpoint de arquitetura de dados do modulo esta executado ou executar a validacao fisica nesta task.',
            ];
        }

        return [
            'Migrations e Models do modulo refletem `database_schema.tables` e `blueprint_context.project_domain_model`.',
            'Relacionamentos Eloquent representam as cardinalidades esperadas no Blueprint/MER.',
            'As migrations executam com sucesso em SQLite temporario de arquivo local.',
            'Antes da aprovacao do orcamento, a validacao permanece no SQLite temporario e nos artefatos `.ai-dev/architecture`.',
            'Depois da aprovacao do orcamento e do scaffold fisico, o schema validado executa com sucesso no Postgres de desenvolvimento/staging do Projeto Alvo.',
            '`.ai-dev/architecture/domain-model.*` e ERD fisico/textual foram conferidos ou atualizados.',
            'Nao existem tabelas isoladas sem justificativa explicita no PRD.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function constraints(bool $isArchitectureCheckpoint): array
    {
        $constraints = [
            'Usar a stack TALL + Filament v5 definida pelo projeto alvo.',
            'Consultar Boost do projeto alvo antes de implementar.',
            'Consultar `.ai-dev/architecture/domain-model.*` antes de criar ou alterar Models, migrations, Resources, Controllers ou APIs.',
        ];

        if (! $isArchitectureCheckpoint) {
            $constraints[] = 'Nao implementar UI/Filament/API sobre schema incerto; execute ou valide o checkpoint de arquitetura de dados primeiro.';

            return $constraints;
        }

        return [
            ...$constraints,
            'Usar SQLite somente como prototipo local descartavel (`database/ai_dev_architecture.sqlite`).',
            'Nao commitar o arquivo SQLite temporario.',
            'Nao executar `migrate:fresh` em banco com dados reais de producao.',
            'Declarar relacionamentos Eloquent antes de gerar ERD fisico.',
        ];
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function projectDomainModel(ProjectModule $module): ?array
    {
        $blueprint = $module->project?->blueprint_payload;

        if (! is_array($blueprint) || ! is_array($blueprint['domain_model'] ?? null)) {
            return null;
        }

        return $blueprint['domain_model'];
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->stringValue(...), $value)));
        }

        return trim((string) $value);
    }
}
