<?php

use App\Enums\ModuleStatus;
use App\Models\Project;
use App\Services\StandardProjectModuleService;

test('standard project modules are synced idempotently', function () {
    $project = Project::create([
        'name' => 'standard-modules-project',
        'status' => 'active',
    ]);

    $service = app(StandardProjectModuleService::class);

    $service->syncProject($project);
    $service->syncProject($project);

    $rootModules = $project->modules()
        ->whereNull('parent_id')
        ->orderBy('name')
        ->get();

    $security = $project->modules()->where('name', 'Segurança')->firstOrFail();
    $chatbox = $project->modules()->where('name', 'Chatbox')->firstOrFail();

    expect($project->modules()->count())->toBe(5)
        ->and($rootModules->pluck('name')->all())->toBe(['Chatbox', 'Segurança'])
        ->and($security->children()->pluck('name')->sort()->values()->all())->toBe([
            'Logs de Atividades',
            'Perfis de Usuários',
            'Usuários',
        ])
        ->and($chatbox->status)->toBe(ModuleStatus::Completed)
        ->and($chatbox->progress_percentage)->toBe(100.0)
        ->and($chatbox->prd_payload['standard_module'])->toBeTrue()
        ->and($security->blueprint_payload['source'])->toBe(StandardProjectModuleService::SOURCE);
});

test('standard modules are merged into project prd outside business modules', function () {
    $service = app(StandardProjectModuleService::class);

    $prd = $service->mergeIntoProjectPrd([
        'title' => 'Projeto Teste',
        'objective' => 'Validar regra de módulos padrão.',
        'modules' => [
            ['name' => 'Chatbox', 'description' => 'Duplicado padrão'],
            ['name' => 'Segurança', 'description' => 'Duplicado padrão'],
            ['name' => 'CRM', 'description' => 'Gestão comercial'],
        ],
    ]);

    expect($prd['modules'])->toHaveCount(1)
        ->and($prd['modules'][0]['name'])->toBe('CRM')
        ->and($prd['standard_modules'])->toHaveCount(2)
        ->and(collect($prd['standard_modules'])->pluck('name')->all())->toBe(['Chatbox', 'Segurança'])
        ->and($prd['standard_modules_policy'])->toContain('instalados automaticamente');
});
