<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectModule;

class ProjectBlueprintService
{
    private const int MAX_ENTITIES = 200;

    private const int MAX_COLUMNS_PER_ENTITY = 120;

    private const int MAX_RELATIONSHIPS = 500;

    private const int MAX_ARTIFACTS_PER_GROUP = 250;

    /**
     * @return array<string, mixed>
     */
    public function seedFromPrd(Project $project): array
    {
        $prd = is_array($project->prd_payload) ? $project->prd_payload : [];

        return [
            'title' => "{$project->name} — Blueprint Técnico",
            'artifact_type' => 'technical_blueprint',
            'source' => 'project_prd_seed',
            'summary' => $this->stringValue($prd['scope_summary'] ?? $prd['objective'] ?? ''),
            'domain_model' => [
                'entities' => [],
                'relationships' => [],
            ],
            'use_cases' => $this->normalizeNamedList($prd['use_cases'] ?? []),
            'workflows' => $this->normalizeNamedList($prd['workflows'] ?? []),
            'architecture' => [
                'containers' => [
                    [
                        'name' => 'Aplicação Laravel TALL',
                        'description' => 'Aplicação Laravel 13 com Livewire 4, Alpine.js, Tailwind CSS v4, Filament v5 e Anime.js.',
                    ],
                    [
                        'name' => 'PostgreSQL',
                        'description' => 'Banco relacional próprio do Projeto Alvo.',
                    ],
                ],
                'components' => [],
                'integrations' => [],
            ],
            'api_surface' => [],
            'non_functional_decisions' => $this->normalizeStringList($prd['non_functional_requirements'] ?? []),
            'open_questions' => [],
            'module_notes' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    public function normalize(Project $project, array $blueprint): array
    {
        $normalized = array_replace_recursive($this->seedFromPrd($project), $blueprint);

        $entities = $this->normalizeEntities($normalized['domain_model']['entities'] ?? []);
        $relationships = $this->normalizeRelationships($normalized['domain_model']['relationships'] ?? []);

        $normalized['domain_model'] = [
            'entities' => array_slice($entities, 0, self::MAX_ENTITIES),
            'relationships' => array_slice($relationships, 0, self::MAX_RELATIONSHIPS),
        ];

        $normalized['use_cases'] = $this->normalizeNamedList($normalized['use_cases'] ?? []);
        $normalized['workflows'] = $this->normalizeNamedList($normalized['workflows'] ?? []);
        $normalized['api_surface'] = $this->normalizeNamedList($normalized['api_surface'] ?? []);
        $normalized['non_functional_decisions'] = $this->normalizeStringList($normalized['non_functional_decisions'] ?? []);
        $normalized['open_questions'] = $this->normalizeStringList($normalized['open_questions'] ?? []);
        $normalized['module_notes'] = $this->normalizeNamedList($normalized['module_notes'] ?? []);

        $architecture = is_array($normalized['architecture'] ?? null) ? $normalized['architecture'] : [];
        $normalized['architecture'] = [
            'containers' => $this->normalizeNamedList($architecture['containers'] ?? []),
            'components' => $this->normalizeNamedList($architecture['components'] ?? []),
            'integrations' => $this->normalizeNamedList($architecture['integrations'] ?? []),
        ];

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $modulePrd
     * @return array<string, mixed>
     */
    public function mergeModulePrd(ProjectModule $module, array $modulePrd): array
    {
        $project = $module->project;
        $current = $this->normalize($project, is_array($project->blueprint_payload) ? $project->blueprint_payload : []);
        $contribution = $this->extractModuleContribution($module, $modulePrd);

        unset($current['_status'], $current['_error']);
        $current['source'] = 'progressive_blueprint';

        $current['domain_model']['entities'] = $this->mergeEntities(
            $current['domain_model']['entities'] ?? [],
            $contribution['domain_model']['entities'] ?? [],
        );

        $current['domain_model']['relationships'] = $this->mergeRelationships(
            $current['domain_model']['relationships'] ?? [],
            $contribution['domain_model']['relationships'] ?? [],
        );

        $current['use_cases'] = $this->mergeNamedItems($current['use_cases'] ?? [], $contribution['use_cases'] ?? []);
        $current['workflows'] = $this->mergeNamedItems($current['workflows'] ?? [], $contribution['workflows'] ?? []);
        $current['api_surface'] = $this->mergeNamedItems($current['api_surface'] ?? [], $contribution['api_surface'] ?? []);
        $current['architecture']['components'] = $this->mergeNamedItems(
            $current['architecture']['components'] ?? [],
            $contribution['architecture']['components'] ?? [],
        );
        $current['architecture']['integrations'] = $this->mergeNamedItems(
            $current['architecture']['integrations'] ?? [],
            $contribution['architecture']['integrations'] ?? [],
        );
        $current['module_notes'] = $this->mergeNamedItems($current['module_notes'] ?? [], [
            [
                'name' => $module->name,
                'description' => $this->stringValue($modulePrd['objective'] ?? $modulePrd['scope'] ?? ''),
                'module_id' => $module->id,
            ],
        ]);

        $module->update(['blueprint_payload' => $contribution]);
        $project->update(['blueprint_payload' => $current]);

        return $current;
    }

    /**
     * @param  array<string, mixed>  $modulePrd
     * @return array<string, mixed>
     */
    public function extractModuleContribution(ProjectModule $module, array $modulePrd): array
    {
        $raw = is_array($modulePrd['blueprint_contribution'] ?? null)
            ? $modulePrd['blueprint_contribution']
            : [];

        $entities = $this->normalizeEntities($raw['domain_model']['entities'] ?? $raw['entities'] ?? [], $module);
        $relationships = $this->normalizeRelationships(
            $raw['domain_model']['relationships'] ?? $raw['relationships'] ?? [],
            null,
            $module,
        );

        foreach ($modulePrd['database_schema']['tables'] ?? [] as $table) {
            if (! is_array($table)) {
                continue;
            }

            $entity = $this->normalizeEntity([
                'name' => $table['name'] ?? '',
                'description' => $table['description'] ?? '',
                'columns' => $table['columns'] ?? [],
                'relationships' => $table['relations'] ?? $table['relationships'] ?? [],
            ], $module);

            if ($entity !== []) {
                $entities[] = $entity;
            }
        }

        $entities = $this->mergeEntities([], $entities);

        foreach ($entities as $entity) {
            $relationships = $this->mergeRelationships(
                $relationships,
                $this->normalizeRelationships($entity['relationships'] ?? [], $entity['name'] ?? null, $module),
            );
        }

        return [
            'module_id' => $module->id,
            'module_name' => $module->name,
            'module_path' => $this->modulePath($module),
            'domain_model' => [
                'entities' => array_slice($entities, 0, self::MAX_ENTITIES),
                'relationships' => array_slice($relationships, 0, self::MAX_RELATIONSHIPS),
            ],
            'use_cases' => $this->normalizeNamedList($raw['use_cases'] ?? $modulePrd['use_cases'] ?? [], $module),
            'workflows' => $this->normalizeNamedList($raw['workflows'] ?? $modulePrd['workflows'] ?? [], $module),
            'architecture' => [
                'components' => $this->normalizeNamedList(
                    $raw['architecture']['components'] ?? $raw['architecture_components'] ?? $modulePrd['components'] ?? [],
                    $module,
                ),
                'integrations' => $this->normalizeNamedList(
                    $raw['architecture']['integrations'] ?? $raw['integrations'] ?? [],
                    $module,
                ),
            ],
            'api_surface' => $this->normalizeNamedList(
                $raw['api_surface'] ?? $raw['api_contracts'] ?? $modulePrd['api_endpoints'] ?? [],
                $module,
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $entities
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEntities(mixed $entities, ?ProjectModule $module = null): array
    {
        if (! is_array($entities)) {
            return [];
        }

        return collect($entities)
            ->filter(fn (mixed $entity): bool => is_array($entity))
            ->map(fn (array $entity): array => $this->normalizeEntity($entity, $module))
            ->filter(fn (array $entity): bool => $entity !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    private function normalizeEntity(array $entity, ?ProjectModule $module = null): array
    {
        $name = $this->stringValue($entity['name'] ?? $entity['table'] ?? '');

        if ($name === '') {
            return [];
        }

        $modules = $this->normalizeStringList($entity['modules'] ?? $entity['source_modules'] ?? []);
        if ($module !== null) {
            $modules[] = $module->name;
            $modules = array_values(array_unique($modules));
        }

        return [
            'name' => $name,
            'description' => $this->stringValue($entity['description'] ?? ''),
            'modules' => $modules,
            'columns' => array_slice($this->normalizeColumns($entity['columns'] ?? $entity['fields'] ?? []), 0, self::MAX_COLUMNS_PER_ENTITY),
            'relationships' => array_slice($this->normalizeRelationships($entity['relationships'] ?? $entity['relations'] ?? [], $name, $module), 0, self::MAX_RELATIONSHIPS),
        ];
    }

    /**
     * @param  array<int, mixed>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function normalizeColumns(mixed $columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        return collect($columns)
            ->filter(fn (mixed $column): bool => is_array($column))
            ->map(function (array $column): array {
                $name = $this->stringValue($column['name'] ?? '');

                if ($name === '') {
                    return [];
                }

                return [
                    'name' => $name,
                    'type' => $this->stringValue($column['type'] ?? 'string'),
                    'nullable' => (bool) ($column['nullable'] ?? false),
                    'description' => $this->stringValue($column['description'] ?? $column['purpose'] ?? ''),
                    'source' => $this->stringValue($column['source'] ?? 'module_prd'),
                ];
            })
            ->filter(fn (array $column): bool => $column !== [])
            ->unique(fn (array $column): string => $this->normalizeName($column['name']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $relationships
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRelationships(mixed $relationships, ?string $sourceEntity = null, ?ProjectModule $module = null): array
    {
        if (! is_array($relationships)) {
            return [];
        }

        return collect($relationships)
            ->filter(fn (mixed $relationship): bool => is_array($relationship))
            ->map(function (array $relationship) use ($sourceEntity, $module): array {
                $source = $this->stringValue($relationship['source'] ?? $relationship['from'] ?? $sourceEntity ?? '');
                $target = $this->stringValue($relationship['target'] ?? $relationship['to'] ?? $relationship['table'] ?? '');

                if ($source === '' || $target === '') {
                    return [];
                }

                return [
                    'source' => $source,
                    'target' => $target,
                    'type' => $this->stringValue($relationship['type'] ?? $relationship['cardinality'] ?? 'related_to'),
                    'foreign_key' => $this->stringValue($relationship['foreign_key'] ?? ''),
                    'description' => $this->stringValue($relationship['description'] ?? ''),
                    'module' => $module?->name,
                ];
            })
            ->filter(fn (array $relationship): bool => $relationship !== [])
            ->unique(fn (array $relationship): string => $this->relationshipKey($relationship))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $base
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeEntities(array $base, array $incoming): array
    {
        $merged = [];

        foreach ([...$base, ...$incoming] as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $normalized = $this->normalizeEntity($entity);
            if ($normalized === []) {
                continue;
            }

            $key = $this->normalizeName($normalized['name']);
            $existing = $merged[$key] ?? null;

            if ($existing === null) {
                $merged[$key] = $normalized;

                continue;
            }

            $merged[$key]['description'] = $existing['description'] !== ''
                ? $existing['description']
                : $normalized['description'];
            $merged[$key]['modules'] = array_values(array_unique([
                ...($existing['modules'] ?? []),
                ...($normalized['modules'] ?? []),
            ]));
            $merged[$key]['columns'] = $this->mergeNamedItems($existing['columns'] ?? [], $normalized['columns'] ?? [], self::MAX_COLUMNS_PER_ENTITY);
            $merged[$key]['relationships'] = $this->mergeRelationships($existing['relationships'] ?? [], $normalized['relationships'] ?? []);
        }

        return array_slice(array_values($merged), 0, self::MAX_ENTITIES);
    }

    /**
     * @param  array<int, array<string, mixed>>  $base
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeRelationships(array $base, array $incoming): array
    {
        $merged = [];

        foreach ([...$base, ...$incoming] as $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $normalized = $this->normalizeRelationships([$relationship])[0] ?? null;
            if ($normalized === null) {
                continue;
            }

            $merged[$this->relationshipKey($normalized)] = array_filter($normalized, fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return array_slice(array_values($merged), 0, self::MAX_RELATIONSHIPS);
    }

    /**
     * @param  array<int, mixed>  $base
     * @param  array<int, mixed>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeNamedItems(array $base, array $incoming, int $limit = self::MAX_ARTIFACTS_PER_GROUP): array
    {
        $merged = [];

        foreach ([...$base, ...$incoming] as $item) {
            $normalized = $this->normalizeNamedItem($item);
            if ($normalized === []) {
                continue;
            }

            $merged[$this->namedItemKey($normalized)] = array_replace($merged[$this->namedItemKey($normalized)] ?? [], $normalized);
        }

        return array_slice(array_values($merged), 0, $limit);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNamedList(mixed $items, ?ProjectModule $module = null): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn (mixed $item): array => $this->normalizeNamedItem($item, $module))
            ->filter(fn (array $item): bool => $item !== [])
            ->unique(fn (array $item): string => $this->namedItemKey($item))
            ->take(self::MAX_ARTIFACTS_PER_GROUP)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeNamedItem(mixed $item, ?ProjectModule $module = null): array
    {
        if (is_string($item)) {
            $item = ['name' => $item];
        }

        if (! is_array($item)) {
            return [];
        }

        $name = $this->stringValue($item['name'] ?? $item['title'] ?? $item['uri'] ?? $item['method'] ?? '');
        if ($name === '') {
            return [];
        }

        $item['name'] = $name;

        if ($module !== null) {
            $item['module_id'] = $module->id;
            $item['module_name'] = $module->name;
        }

        return $item;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn (mixed $item): string => $this->stringValue($item))
            ->filter()
            ->unique(fn (string $item): string => $this->normalizeName($item))
            ->values()
            ->all();
    }

    private function relationshipKey(array $relationship): string
    {
        return implode('|', [
            $this->normalizeName($relationship['source'] ?? ''),
            $this->normalizeName($relationship['type'] ?? ''),
            $this->normalizeName($relationship['target'] ?? ''),
            $this->normalizeName($relationship['foreign_key'] ?? ''),
        ]);
    }

    private function namedItemKey(array $item): string
    {
        return $this->normalizeName($item['name'] ?? $item['uri'] ?? json_encode($item));
    }

    private function modulePath(ProjectModule $module): string
    {
        $path = [$module->name];
        $parent = $module->parent;

        while ($parent !== null) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map(fn (mixed $item): string => $this->stringValue($item), $value)));
        }

        return trim((string) $value);
    }
}
