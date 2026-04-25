<?php

namespace App\Services;

use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Models\Project;
use App\Models\ProjectModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StandardProjectModuleService
{
    public const string SOURCE = 'ai_dev_core_standard';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'key' => 'chatbox',
                'name' => 'Chatbox',
                'description' => 'Assistente conversacional padrão do painel administrativo, com histórico de sessão, auditoria de uso e leitura segura do contexto do projeto.',
                'priority' => Priority::High,
                'needs_submodules' => false,
                'components' => [
                    'DashboardChat widget',
                    'SystemAssistantAgent',
                    'BoostTool restrito',
                    'FileReadTool com bloqueios de arquivos sensíveis',
                ],
                'entities' => [
                    [
                        'name' => 'activity_log',
                        'description' => 'Registra eventos de uso do chatbox e limpeza do histórico.',
                    ],
                ],
                'children' => [],
            ],
            [
                'key' => 'security',
                'name' => 'Segurança',
                'description' => 'Bloco administrativo padrão para usuários, perfis, permissões e auditoria, baseado na estrutura já utilizada pelo ai-dev-core.',
                'priority' => Priority::High,
                'needs_submodules' => true,
                'components' => [
                    'Filament Shield',
                    'Spatie Permission',
                    'Spatie Activitylog',
                    'Sincronização automática de permissões',
                ],
                'entities' => [
                    ['name' => 'users', 'description' => 'Usuários com acesso ao painel administrativo.'],
                    ['name' => 'roles', 'description' => 'Perfis de acesso do painel.'],
                    ['name' => 'permissions', 'description' => 'Permissões granulares por recurso, página e widget.'],
                    ['name' => 'activity_log', 'description' => 'Log auditável de ações do sistema.'],
                ],
                'children' => [
                    [
                        'key' => 'security.users',
                        'name' => 'Usuários',
                        'description' => 'CRUD padrão de usuários do painel com associação a perfis de acesso.',
                        'priority' => Priority::High,
                        'needs_submodules' => false,
                        'components' => ['UserResource', 'UserForm', 'UsersTable'],
                        'entities' => [
                            ['name' => 'users', 'description' => 'Usuários autenticáveis do painel.'],
                            ['name' => 'model_has_roles', 'description' => 'Associação de usuários a perfis.'],
                        ],
                    ],
                    [
                        'key' => 'security.roles',
                        'name' => 'Perfis de Usuários',
                        'description' => 'Gestão de perfis e permissões via Filament Shield e Spatie Permission.',
                        'priority' => Priority::High,
                        'needs_submodules' => false,
                        'components' => ['RoleResource', 'FilamentShieldPermissionSyncService'],
                        'entities' => [
                            ['name' => 'roles', 'description' => 'Perfis de acesso.'],
                            ['name' => 'permissions', 'description' => 'Permissões sincronizadas com superfícies Filament.'],
                            ['name' => 'role_has_permissions', 'description' => 'Associação de permissões aos perfis.'],
                        ],
                    ],
                    [
                        'key' => 'security.activity_logs',
                        'name' => 'Logs de Atividades',
                        'description' => 'Consulta auditável de eventos do sistema, com filtros dinâmicos por módulo, evento e usuário.',
                        'priority' => Priority::High,
                        'needs_submodules' => false,
                        'components' => ['ActivityLogResource', 'ActivityAuditService', 'SystemSurfaceMapService'],
                        'entities' => [
                            ['name' => 'activity_log', 'description' => 'Eventos auditáveis de modelos, permissões e chatbox.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function promptSummary(): string
    {
        $modules = collect($this->definitions())
            ->map(fn (array $definition): string => '- '.$definition['name'].': '.$definition['description'])
            ->implode("\n");

        return <<<TEXT
MÓDULOS PADRÃO JÁ EXISTENTES:
{$modules}

Regra: não inclua Chatbox nem Segurança nos módulos de negócio do PRD. Eles serão anexados automaticamente ao PRD em `standard_modules`, criados no banco como módulos concluídos e copiados fisicamente para o projeto alvo durante o scaffold disparado após a aprovação do orçamento.
TEXT;
    }

    /**
     * @param  array<string, mixed>  $prd
     * @return array<string, mixed>
     */
    public function mergeIntoProjectPrd(array $prd): array
    {
        $businessModules = collect($prd['modules'] ?? [])
            ->filter(fn (mixed $module): bool => is_array($module))
            ->reject(fn (array $module): bool => $this->isStandardModuleName($this->stringValue($module['name'] ?? '')))
            ->values()
            ->all();

        $prd['modules'] = $businessModules;
        $prd['standard_modules'] = $this->prdDefinitions();
        $prd['standard_modules_policy'] = 'Chatbox e Segurança são módulos padrão herdados do ai-dev-core. Eles não entram no planejamento de módulos de negócio e são instalados automaticamente em todo projeto novo.';

        return $prd;
    }

    /**
     * @param  array<string, mixed>  $prd
     * @return Collection<int, array<string, mixed>>
     */
    public function businessModulesFromPrd(array $prd): Collection
    {
        return collect($prd['modules'] ?? [])
            ->filter(fn (mixed $moduleData): bool => is_array($moduleData))
            ->reject(fn (array $moduleData): bool => $this->isStandardModuleName($this->stringValue($moduleData['name'] ?? '')));
    }

    /**
     * @return Collection<int, ProjectModule>
     */
    public function syncProject(Project $project): Collection
    {
        $modules = collect();

        foreach ($this->definitions() as $definition) {
            $root = $this->upsertModule($project, $definition);
            $modules->push($root);

            foreach ($definition['children'] ?? [] as $childDefinition) {
                $modules->push($this->upsertModule($project, $childDefinition, $root));
            }
        }

        return $modules;
    }

    /**
     * @return array<int, string>
     */
    public function standardRootModuleIds(Project $project): array
    {
        $rootNames = collect($this->definitions())
            ->map(fn (array $definition): string => $this->normalizeModuleName($definition['name']))
            ->all();

        return $project->modules()
            ->whereNull('parent_id')
            ->get()
            ->filter(fn (ProjectModule $module): bool => in_array($this->normalizeModuleName($module->name), $rootNames, true))
            ->pluck('id')
            ->values()
            ->all();
    }

    public function isStandardModuleName(string $name): bool
    {
        $normalized = $this->normalizeModuleName($name);

        if ($normalized === '') {
            return false;
        }

        return collect($this->definitions())
            ->flatMap(fn (array $definition): array => [
                $definition['name'],
                ...collect($definition['children'] ?? [])->pluck('name')->all(),
            ])
            ->map(fn (string $standardName): string => $this->normalizeModuleName($standardName))
            ->contains($normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prdDefinitions(): array
    {
        return collect($this->definitions())
            ->map(fn (array $definition): array => $this->toPrdModule($definition))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function toPrdModule(array $definition): array
    {
        return [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'priority' => $this->priorityValue($definition['priority'] ?? Priority::High),
            'status' => 'preinstalled',
            'source' => self::SOURCE,
            'implemented_by_template' => true,
            'needs_submodules' => (bool) ($definition['needs_submodules'] ?? false),
            'submodules' => collect($definition['children'] ?? [])
                ->map(fn (array $child): array => $this->toPrdModule($child))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsertModule(Project $project, array $definition, ?ProjectModule $parent = null): ProjectModule
    {
        $query = $project->modules()
            ->where('name', $definition['name']);

        $parent === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parent->id);

        $module = $query->first();

        $payload = [
            'project_id' => $project->id,
            'parent_id' => $parent?->id,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'status' => ModuleStatus::Completed,
            'priority' => $definition['priority'] ?? Priority::High,
            'dependencies' => null,
            'progress_percentage' => 100,
            'prd_payload' => $this->modulePrdPayload($definition),
            'blueprint_payload' => $this->moduleBlueprintPayload($definition, $parent),
            'completed_at' => now(),
        ];

        if ($module) {
            $module->fill($payload);
            $module->save();

            return $module;
        }

        return ProjectModule::create($payload);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function modulePrdPayload(array $definition): array
    {
        return [
            'title' => $definition['name'].' — PRD Técnico Padrão',
            'objective' => $definition['description'],
            'scope' => 'Módulo padrão herdado do ai-dev-core. Não deve gerar tasks de implementação em projetos novos, pois seus arquivos são copiados durante o scaffold disparado após a aprovação do orçamento.',
            'source' => self::SOURCE,
            'standard_module' => true,
            'needs_submodules' => (bool) ($definition['needs_submodules'] ?? false),
            'submodules' => collect($definition['children'] ?? [])
                ->map(fn (array $child): array => [
                    'name' => $child['name'],
                    'description' => $child['description'],
                    'priority' => $this->priorityValue($child['priority'] ?? Priority::High),
                ])
                ->values()
                ->all(),
            'acceptance_criteria' => [
                'Arquivos base copiados para o projeto alvo.',
                'Permissões sincronizadas para super_admin.',
                'Módulo marcado como concluído no planejamento do ai-dev-core.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function moduleBlueprintPayload(array $definition, ?ProjectModule $parent = null): array
    {
        return [
            'module_name' => $definition['name'],
            'module_path' => $parent ? "{$parent->name} / {$definition['name']}" : $definition['name'],
            'source' => self::SOURCE,
            'domain_model' => [
                'entities' => $definition['entities'] ?? [],
                'relationships' => [],
            ],
            'use_cases' => [
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                ],
            ],
            'workflows' => [],
            'architecture' => [
                'components' => collect($definition['components'] ?? [])
                    ->map(fn (string $component): array => [
                        'name' => $component,
                        'description' => "Componente padrão do módulo {$definition['name']}.",
                    ])
                    ->values()
                    ->all(),
                'integrations' => [],
            ],
            'api_surface' => [],
        ];
    }

    private function priorityValue(Priority|string $priority): string
    {
        return $priority instanceof Priority ? $priority->value : $priority;
    }

    private function normalizeModuleName(string $name): string
    {
        return Str::of($name)
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->lower()
            ->toString();
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->stringValue(...), $value)));
        }

        return trim((string) $value);
    }
}
