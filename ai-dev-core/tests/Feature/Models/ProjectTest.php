<?php

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;

test('project can be created', function () {
    $project = Project::create([
        'name' => 'test-project',
        'status' => 'active',
    ]);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->name)->toBe('test-project')
        ->and($project->status->value)->toBe('active');
});

test('project has modules relationship', function () {
    $project = Project::create([
        'name' => 'test-modules-rel',
        'status' => 'active',
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Auth Module',
        'description' => 'Authentication module',
        'status' => 'planned',
    ]);

    expect($project->modules)->toHaveCount(1)
        ->and($project->modules->first()->name)->toBe('Auth Module');
});

test('project has specifications relationship', function () {
    $project = Project::create([
        'name' => 'test-spec-rel',
        'status' => 'active',
    ]);

    ProjectSpecification::create([
        'project_id' => $project->id,
        'user_description' => 'A test system',
        'version' => 1,
    ]);

    expect($project->specifications)->toHaveCount(1)
        ->and($project->currentSpecification)->not->toBeNull();
});

test('project calculates overall progress', function () {
    $project = Project::create([
        'name' => 'test-progress',
        'status' => 'active',
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Module A',
        'description' => 'First',
        'status' => 'completed',
        'progress_percentage' => 100,
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Module B',
        'description' => 'Second',
        'status' => 'planned',
        'progress_percentage' => 0,
    ]);

    expect($project->overallProgress())->toBe(50.0);
});

test('project creates root modules idempotently with cap and deduplication', function () {
    $project = Project::create([
        'name' => 'test-prd-modules',
        'status' => 'active',
        'prd_payload' => [
            'modules' => [
                ['name' => 'Autenticação', 'description' => 'Auth', 'priority' => 'high'],
                ['name' => '  autenticação  ', 'description' => 'Duplicated auth', 'priority' => 'medium'],
                ...collect(range(1, 45))
                    ->map(fn (int $index) => ['name' => "Módulo {$index}", 'description' => "Desc {$index}"])
                    ->all(),
            ],
        ],
    ]);

    $project->createModulesFromPrd();
    $project->createModulesFromPrd();

    expect($project->modules()->whereNull('parent_id')->count())->toBe(Project::MAX_ROOT_MODULES)
        ->and($project->modules()->where('name', 'Autenticação')->count())->toBe(1);
});

test('project approves blueprint only when it is ready', function () {
    $project = Project::create([
        'name' => 'test-blueprint-approval',
        'status' => 'active',
    ]);

    expect(fn () => $project->approveBlueprint())->toThrow(RuntimeException::class);

    $project->update([
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'clientes', 'description' => 'Clientes atendidos'],
                ],
                'relationships' => [],
            ],
        ],
    ]);

    $project->approveBlueprint();

    expect($project->fresh()->blueprint_approved_at)->not->toBeNull();
});
