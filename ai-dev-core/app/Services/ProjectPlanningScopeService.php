<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProjectPlanningScopeService
{
    private const array SIMPLE_INTENT_TERMS = [
        'landing page',
        'pagina de captura',
        'pagina unica',
        'one page',
        'hotsite',
    ];

    private const array SITE_INTENT_TERMS = [
        'site institucional',
        'portfolio',
        'portifolio',
        'vitrine',
    ];

    private const array COMPLEX_SYSTEM_TERMS = [
        'painel administrativo',
        'dashboard administrativo',
        'sistema completo',
        'plataforma completa',
        'crm',
        'erp',
        'marketplace',
        'multiusuario',
        'workflow',
        'gestao interna',
    ];

    private const array SIMPLE_EXPANSION_TERMS = [
        'crm',
        'cotacao',
        'proposta',
        'orcamento',
        'orquestracao',
        'agente',
        'redes sociais',
        'social',
        'webhook',
        'importacao',
        'gestao de projetos',
        'tarefas',
        'analytics',
        'dashboard',
        'painel',
    ];

    /**
     * @return array{key:string,label:string,description:string,root_modules:int,submodules_per_module:int,tasks_per_module:int,feature_limits:array{backend:int,frontend:int}}
     */
    public function profile(Project $project): array
    {
        $text = $this->scopeText($project);

        if ($this->containsAny($text, self::SIMPLE_INTENT_TERMS) && ! $this->containsAny($text, self::COMPLEX_SYSTEM_TERMS)) {
            return [
                'key' => 'simple_landing',
                'label' => 'landing page simples',
                'description' => 'Escopo enxuto, focado em experiencia publica, conversao e conteudo essencial.',
                'root_modules' => 3,
                'submodules_per_module' => 0,
                'tasks_per_module' => 12,
                'feature_limits' => [
                    'backend' => 3,
                    'frontend' => 8,
                ],
            ];
        }

        if ($this->containsAny($text, self::SITE_INTENT_TERMS) && ! $this->containsAny($text, self::COMPLEX_SYSTEM_TERMS)) {
            return [
                'key' => 'public_site',
                'label' => 'site publico',
                'description' => 'Escopo moderado de site publico, sem assumir operacao administrativa completa.',
                'root_modules' => 5,
                'submodules_per_module' => 3,
                'tasks_per_module' => 18,
                'feature_limits' => [
                    'backend' => 6,
                    'frontend' => 10,
                ],
            ];
        }

        return [
            'key' => 'application',
            'label' => 'aplicacao',
            'description' => 'Escopo de sistema aplicacional conforme funcionalidades cadastradas.',
            'root_modules' => (int) config('ai_dev.planning.max_root_modules_per_project', 40),
            'submodules_per_module' => (int) config('ai_dev.planning.max_submodules_per_module', 8),
            'tasks_per_module' => (int) config('ai_dev.planning.max_tasks_per_module', 30),
            'feature_limits' => [
                'backend' => (int) config('ai_dev.planning.max_generated_backend_features', 20),
                'frontend' => (int) config('ai_dev.planning.max_generated_frontend_features', 20),
            ],
        ];
    }

    public function promptGuidance(Project $project, ?string $action = null): string
    {
        $profile = $this->profile($project);
        $actionLine = $action ? "ACAO ATUAL: {$action}\n" : '';

        return <<<TEXT
CONTRATO DE ESCOPO DO AI-DEV:
{$actionLine}Perfil detectado: {$profile['label']}.
Diretriz: {$profile['description']}

Regras obrigatorias:
- Use a descricao do projeto e as funcionalidades cadastradas como fonte de verdade.
- Nao transforme servicos que serao apresentados no site em modulos internos do sistema, a menos que tenham sido pedidos como funcionalidade operacional.
- Nao crie dominios novos para "completar" o produto; se algo nao foi pedido, deixe fora.
- Esta acao deve produzir apenas seu artefato proprio. Nao assuma o papel das proximas etapas.
- Para este perfil, use no maximo {$profile['root_modules']} modulos raiz de negocio, {$profile['submodules_per_module']} submodulos por modulo e {$profile['tasks_per_module']} tasks por modulo folha, salvo se o usuario pedir explicitamente mais.
TEXT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, array<string, string>>
     */
    public function sanitizeFeatures(Project $project, string $type, array $features): array
    {
        $profile = $this->profile($project);
        $limit = $profile['feature_limits'][$type] ?? 20;
        $scopeText = $this->scopeText($project, includeExistingFeatures: false);
        $simple = in_array($profile['key'], ['simple_landing', 'public_site'], true);

        return collect($features)
            ->filter(fn (mixed $feature): bool => is_array($feature))
            ->map(fn (array $feature): array => [
                'title' => $this->stringValue($feature['title'] ?? ''),
                'description' => $this->stringValue($feature['description'] ?? ''),
            ])
            ->filter(fn (array $feature): bool => $feature['title'] !== '')
            ->reject(fn (array $feature): bool => $simple && $this->isUnrequestedExpansion($feature, $scopeText))
            ->unique(fn (array $feature): string => $this->normalize($feature['title']))
            ->take($limit > 0 ? $limit : PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $prd
     * @return array<string, mixed>
     */
    public function sanitizeProjectPrd(Project $project, array $prd): array
    {
        $profile = $this->profile($project);
        $modules = collect($prd['modules'] ?? [])
            ->filter(fn (mixed $module): bool => is_array($module))
            ->map(fn (array $module): array => [
                ...$module,
                'name' => $this->stringValue($module['name'] ?? ''),
                'description' => $this->stringValue($module['description'] ?? ''),
                'priority' => $this->priorityValue($module['priority'] ?? 'medium'),
                'dependencies' => is_array($module['dependencies'] ?? null) ? $module['dependencies'] : [],
            ])
            ->filter(fn (array $module): bool => $module['name'] !== '')
            ->reject(fn (array $module): bool => in_array($profile['key'], ['simple_landing', 'public_site'], true)
                && $this->isUnrequestedExpansion($module, $this->scopeText($project, includeExistingFeatures: false)))
            ->unique(fn (array $module): string => $this->normalize($module['name']))
            ->values();

        if ($profile['key'] === 'simple_landing') {
            $modules = $this->landingModules($project, $modules);
        } elseif ($profile['key'] === 'public_site' && $modules->count() > $profile['root_modules']) {
            $modules = $this->prioritizePublicSiteModules($modules);
        }

        $limit = $profile['root_modules'] > 0 ? $profile['root_modules'] : PHP_INT_MAX;
        $prd['modules'] = $modules
            ->take($limit)
            ->map(fn (array $module): array => [
                'name' => $module['name'],
                'description' => $module['description'],
                'priority' => $module['priority'],
                'dependencies' => $module['dependencies'] ?? [],
            ])
            ->values()
            ->all();

        $prd['planning_profile'] = [
            'key' => $profile['key'],
            'label' => $profile['label'],
            'root_module_limit' => $profile['root_modules'],
            'submodules_per_module' => $profile['submodules_per_module'],
            'tasks_per_module' => $profile['tasks_per_module'],
        ];

        return $prd;
    }

    public function rootModuleLimit(Project $project): ?int
    {
        $limit = $this->profile($project)['root_modules'];

        return $limit > 0 ? $limit : null;
    }

    public function submoduleLimit(Project $project): ?int
    {
        $limit = $this->profile($project)['submodules_per_module'];

        return $limit > 0 ? $limit : 0;
    }

    public function taskLimit(Project $project): ?int
    {
        $limit = $this->profile($project)['tasks_per_module'];

        return $limit > 0 ? $limit : null;
    }

    private function scopeText(Project $project, bool $includeExistingFeatures = true): string
    {
        $parts = [
            $project->name,
            $project->description,
        ];

        if ($includeExistingFeatures) {
            $features = $project->relationLoaded('features')
                ? $project->features
                : $project->features()->get();

            foreach ($features as $feature) {
                $parts[] = $feature->title;
                $parts[] = $feature->description;
            }
        }

        return Str::of(implode(' ', array_filter($parts)))
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $generated
     * @return Collection<int, array<string, mixed>>
     */
    private function landingModules(Project $project, Collection $generated): Collection
    {
        $text = $this->scopeText($project);
        $modules = collect([
            [
                'name' => 'Landing Page',
                'description' => 'Experiencia publica principal com apresentacao, proposta de valor, secoes de conteudo, chamadas para acao e responsividade.',
                'priority' => 'high',
                'dependencies' => [],
            ],
        ]);

        if ($this->containsAny($text, ['contato', 'lead', 'orcamento', 'formulario', 'captura'])) {
            $modules->push([
                'name' => 'Captacao de Contatos',
                'description' => 'Recebimento e registro das mensagens ou solicitacoes enviadas pelos visitantes, sem assumir CRM completo.',
                'priority' => 'high',
                'dependencies' => ['Landing Page'],
            ]);
        }

        if ($this->containsAny($text, ['seo', 'metricas', 'analytics', 'performance'])) {
            $modules->push([
                'name' => 'SEO e Performance',
                'description' => 'Metadados, desempenho e acompanhamento basico da pagina publica conforme solicitado.',
                'priority' => 'medium',
                'dependencies' => ['Landing Page'],
            ]);
        }

        if ($modules->count() === 1 && $generated->isNotEmpty()) {
            return $generated->take(3)->values();
        }

        return $modules->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $modules
     * @return Collection<int, array<string, mixed>>
     */
    private function prioritizePublicSiteModules(Collection $modules): Collection
    {
        $priorityTerms = ['site', 'landing', 'portfolio', 'contato', 'lead', 'seo', 'performance'];

        return $modules
            ->sortByDesc(function (array $module) use ($priorityTerms): int {
                $text = $this->normalize(($module['name'] ?? '').' '.($module['description'] ?? ''));

                return collect($priorityTerms)->filter(fn (string $term): bool => str_contains($text, $term))->count();
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isUnrequestedExpansion(array $item, string $scopeText): bool
    {
        $text = $this->normalize(($item['title'] ?? $item['name'] ?? '').' '.($item['description'] ?? ''));

        foreach (self::SIMPLE_EXPANSION_TERMS as $term) {
            $normalizedTerm = $this->normalize($term);

            if (str_contains($text, $normalizedTerm) && ! str_contains($scopeText, $normalizedTerm)) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $this->normalize($term))) {
                return true;
            }
        }

        return false;
    }

    private function priorityValue(mixed $value): string
    {
        $value = $this->normalize($this->stringValue($value));

        return match ($value) {
            'high', 'alta' => 'high',
            'low', 'baixa' => 'low',
            default => 'medium',
        };
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->stringValue(...), $value)));
        }

        return trim((string) $value);
    }
}
