<?php

use App\Jobs\GenerateModuleTasksJob;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Services\StandardProjectModuleService;

test('generate module tasks skips standard ai dev modules', function () {
    $project = Project::create([
        'name' => 'standard-module-task-skip',
        'status' => 'active',
    ]);

    app(StandardProjectModuleService::class)->syncProject($project);

    $module = $project->modules()->where('name', 'Chatbox')->firstOrFail();
    $module->forceFill([
        'prd_payload' => array_merge($module->prd_payload, [
            'components' => [
                [
                    'type' => 'Widget',
                    'name' => 'DashboardChat',
                    'description' => 'Componente padrão já entregue pelo AI-Dev Core.',
                ],
            ],
        ]),
    ])->save();

    (new GenerateModuleTasksJob($module))->handle();

    expect($module->tasks()->count())->toBe(0);
});

test('generate module tasks creates data architecture checkpoint before implementation tasks', function () {
    $project = Project::create([
        'name' => 'architecture-checkpoint-project',
        'status' => 'active',
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'clientes'],
                    ['name' => 'processos'],
                ],
                'relationships' => [
                    ['source' => 'clientes', 'target' => 'processos', 'type' => 'one_to_many'],
                ],
            ],
        ],
    ]);

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Processos',
        'description' => 'Modulo de processos juridicos',
        'status' => 'planned',
        'prd_payload' => [
            'title' => 'Processos - PRD Tecnico',
            'objective' => 'Gerenciar processos.',
            'needs_submodules' => false,
            'database_schema' => [
                'tables' => [
                    ['name' => 'processos', 'description' => 'Processos juridicos'],
                ],
            ],
            'components' => [
                ['type' => 'FilamentResource', 'name' => 'ProcessResource', 'description' => 'CRUD de processos'],
            ],
            'acceptance_criteria' => [
                'Processos podem ser cadastrados',
            ],
        ],
    ]);

    (new GenerateModuleTasksJob($module))->handle();

    $tasks = $module->tasks()->orderBy('created_at')->get();

    expect($tasks)->toHaveCount(3)
        ->and($tasks->first()->title)->toBe('Checkpoint de Arquitetura de Dados: Processos')
        ->and($tasks->first()->source)->toBe('architecture')
        ->and($tasks->first()->prd_payload['architecture_checkpoint']['is_checkpoint_task'])->toBeTrue()
        ->and($tasks->pluck('title')->all())->not->toContain('Migration: processos');
});
