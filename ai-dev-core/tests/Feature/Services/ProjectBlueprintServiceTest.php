<?php

use App\Models\Project;
use App\Models\ProjectModule;
use App\Services\ProjectBlueprintService;

test('project blueprint service merges module prd into progressive technical blueprint', function () {
    $project = Project::create([
        'name' => 'test-blueprint-service',
        'status' => 'active',
        'prd_payload' => [
            'title' => 'Projeto Teste',
            'modules' => [
                ['name' => 'Gestão de Clientes', 'description' => 'Clientes e partes'],
            ],
        ],
        'blueprint_payload' => [
            'title' => 'Projeto Teste — Blueprint Técnico',
            'domain_model' => [
                'entities' => [
                    [
                        'name' => 'clientes',
                        'description' => 'Pessoas atendidas',
                        'columns' => [],
                        'relationships' => [],
                    ],
                ],
                'relationships' => [],
            ],
            'use_cases' => [],
            'workflows' => [],
            'architecture' => [
                'containers' => [],
                'components' => [],
                'integrations' => [],
            ],
            'api_surface' => [],
        ],
    ]);

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Gestão de Clientes',
        'description' => 'Cadastro e relacionamento de clientes',
        'status' => 'planned',
    ]);

    $service = app(ProjectBlueprintService::class);

    $service->mergeModulePrd($module, [
        'title' => 'Gestão de Clientes — PRD Técnico',
        'objective' => 'Permitir cadastro e consulta de clientes.',
        'database_schema' => [
            'tables' => [
                [
                    'name' => 'clientes',
                    'description' => 'Cadastro de clientes',
                    'columns' => [
                        ['name' => 'nome', 'type' => 'string', 'nullable' => false],
                        ['name' => 'documento', 'type' => 'string', 'nullable' => false],
                    ],
                    'relations' => [
                        ['table' => 'demandas_juridicas', 'type' => 'hasMany', 'foreign_key' => 'cliente_id'],
                    ],
                ],
            ],
        ],
        'blueprint_contribution' => [
            'workflows' => [
                ['name' => 'Cadastro de cliente', 'steps' => ['Validar documento', 'Salvar cadastro']],
            ],
        ],
    ]);

    $blueprint = $project->fresh()->blueprint_payload;
    $moduleBlueprint = $module->fresh()->blueprint_payload;
    $clientes = collect($blueprint['domain_model']['entities'])->firstWhere('name', 'clientes');

    expect($clientes['columns'])->toHaveCount(2)
        ->and(collect($clientes['columns'])->pluck('name')->all())->toContain('nome', 'documento')
        ->and($blueprint['domain_model']['relationships'])->toHaveCount(1)
        ->and($blueprint['workflows'])->toHaveCount(1)
        ->and($moduleBlueprint['module_name'])->toBe('Gestão de Clientes');
});
