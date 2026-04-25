<?php

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function projectTestCreateReadyScaffold(): string
{
    $path = storage_path('framework/testing/target-scaffold-'.Str::uuid());

    foreach (Project::TARGET_SCAFFOLD_REQUIRED_FILES as $requiredFile) {
        $fullPath = "{$path}/{$requiredFile}";
        File::ensureDirectoryExists(dirname($fullPath));
        File::put($fullPath, 'test');
    }

    return $path;
}

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
    config(['ai_dev.planning.max_root_modules_per_project' => 40]);

    $path = projectTestCreateReadyScaffold();

    try {
        $project = Project::create([
            'name' => 'test-prd-modules',
            'status' => 'active',
            'local_path' => $path,
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

        expect($project->modules()->whereNull('parent_id')->count())->toBe(40)
            ->and($project->modules()->where('name', 'Chatbox')->count())->toBe(1)
            ->and($project->modules()->where('name', 'Segurança')->count())->toBe(1)
            ->and($project->modules()->where('name', 'Autenticação')->count())->toBe(1)
            ->and($project->fresh()->prd_payload['standard_modules'])->toHaveCount(2)
            ->and($project->modules()->where('name', 'Autenticação')->first()->dependencies)->toHaveCount(2);
    } finally {
        File::deleteDirectory($path);
    }
});

test('project approves blueprint only when it is ready', function () {
    $path = projectTestCreateReadyScaffold();

    $project = Project::create([
        'name' => 'test-blueprint-approval',
        'status' => 'active',
        'local_path' => $path,
    ]);

    try {
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
    } finally {
        File::deleteDirectory($path);
    }
});

test('project requires complete target scaffold before blueprint approval', function () {
    $path = storage_path('framework/testing/incomplete-target-scaffold-'.Str::uuid());
    File::ensureDirectoryExists($path);

    $project = Project::create([
        'name' => 'test-incomplete-scaffold',
        'status' => 'active',
        'local_path' => $path,
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'clientes', 'description' => 'Clientes atendidos'],
                ],
            ],
        ],
    ]);

    try {
        expect($project->isTargetScaffoldReady())->toBeFalse()
            ->and($project->targetScaffoldMissingReasons())->not->toBeEmpty()
            ->and(fn () => $project->approveBlueprint())
            ->toThrow(RuntimeException::class, 'scaffold do projeto alvo incompleto');
    } finally {
        File::deleteDirectory($path);
    }
});
