<?php

use App\Jobs\CascadeModulePrdJob;
use App\Jobs\ReconcileProjectCascadeJob;
use App\Jobs\ScaffoldProjectJob;
use App\Jobs\SyncProjectRepositoryJob;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectQuotation;
use App\Models\ProjectSpecification;
use App\Services\StandardProjectModuleService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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

test('project marks prd generation as active and clears stale approvals', function () {
    $project = Project::create([
        'name' => 'test-prd-generation-state',
        'status' => 'active',
        'prd_payload' => [
            'title' => 'Old PRD',
            'modules' => [
                ['name' => 'Old Module'],
            ],
        ],
        'prd_approved_at' => now(),
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'old_entities'],
                ],
                'relationships' => [],
            ],
        ],
        'blueprint_approved_at' => now(),
    ]);

    $project->markPrdGenerationStarted();
    $project->refresh();

    expect($project->isPrdGenerating())->toBeTrue()
        ->and($project->prd_payload)->toBe(['_status' => 'generating'])
        ->and($project->prd_approved_at)->toBeNull()
        ->and($project->blueprint_payload)->toBeNull()
        ->and($project->blueprint_approved_at)->toBeNull();
});

test('project only approves ready prd payloads', function () {
    $project = Project::create([
        'name' => 'test-prd-ready-approval',
        'status' => 'active',
    ]);

    expect(fn () => $project->approvePrd())->toThrow(RuntimeException::class);

    $project->update(['prd_payload' => ['_status' => 'generating']]);
    expect(fn () => $project->fresh()->approvePrd())->toThrow(RuntimeException::class);

    $project->update([
        'prd_payload' => [
            'title' => 'Ready PRD',
            'modules' => [
                ['name' => 'Landing Page'],
            ],
        ],
    ]);

    $project->fresh()->approvePrd();

    expect($project->fresh()->prd_approved_at)->not->toBeNull();
});

test('project marks blueprint generation as active without changing approved prd', function () {
    $project = Project::create([
        'name' => 'test-blueprint-generation-state',
        'status' => 'active',
        'prd_payload' => [
            'title' => 'Ready PRD',
            'modules' => [
                ['name' => 'Landing Page'],
            ],
        ],
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'old_entities'],
                ],
                'relationships' => [],
            ],
        ],
        'blueprint_approved_at' => now(),
    ]);

    $project->approvePrd();
    $approvedAt = $project->fresh()->prd_approved_at;

    $project->markBlueprintGenerationStarted();
    $project->refresh();

    expect($project->isBlueprintGenerating())->toBeTrue()
        ->and($project->blueprint_payload)->toBe(['_status' => 'generating'])
        ->and($project->blueprint_approved_at)->toBeNull()
        ->and($project->prd_approved_at->toISOString())->toBe($approvedAt->toISOString());
});

test('project can approve planning blueprint before target scaffold is installed', function () {
    $path = storage_path('framework/testing/incomplete-target-scaffold-'.Str::uuid());
    File::ensureDirectoryExists($path);

    $project = Project::create([
        'name' => 'test-incomplete-scaffold',
        'status' => 'active',
        'local_path' => $path,
        'prd_payload' => [
            'modules' => [
                ['name' => 'Landing Page', 'description' => 'Página inicial'],
            ],
        ],
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
            ->and($project->targetScaffoldMissingReasons())->not->toBeEmpty();

        $project->approveBlueprint();
        $project->createModulesFromPrd();

        expect($project->fresh()->blueprint_approved_at)->not->toBeNull()
            ->and($project->modules()->where('name', 'Landing Page')->exists())->toBeTrue()
            ->and($project->modules()->where('name', 'Chatbox')->exists())->toBeTrue()
            ->and($project->modules()->where('name', 'Segurança')->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($path);
    }
});

test('quotation approval dispatches target scaffold installation', function () {
    Queue::fake([
        ScaffoldProjectJob::class,
    ]);

    $project = Project::create([
        'name' => 'test-quotation-scaffold',
        'status' => 'active',
        'local_path' => storage_path('framework/testing/missing-target-scaffold-'.Str::uuid()),
    ]);

    $quotation = ProjectQuotation::create([
        'project_id' => $project->id,
        'client_name' => 'Cliente Teste',
        'project_name' => $project->name,
        'project_description' => 'Projeto em planejamento',
        'status' => 'sent',
    ]);

    $quotation->approveAndStartScaffold();

    expect($quotation->fresh()->status)->toBe('approved')
        ->and($quotation->fresh()->approved_at)->not->toBeNull();

    Queue::assertPushed(
        ScaffoldProjectJob::class,
        fn (ScaffoldProjectJob $job): bool => $job->project->id === $project->id && $job->dbPassword !== ''
    );
});

test('cascade reconciler requeues modules with missing or failed planning', function () {
    Queue::fake([
        CascadeModulePrdJob::class,
        ReconcileProjectCascadeJob::class,
        SyncProjectRepositoryJob::class,
    ]);

    $project = Project::create([
        'name' => 'test-cascade-reconcile',
        'status' => 'active',
        'prd_approved_at' => now(),
        'blueprint_approved_at' => now(),
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    ['name' => 'leads'],
                ],
            ],
        ],
    ]);

    $missing = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Módulo sem PRD',
        'description' => 'Precisa ser reprocessado',
        'status' => 'planned',
    ]);

    $failed = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Módulo com PRD falho',
        'description' => 'Precisa tentar novamente',
        'status' => 'planned',
        'prd_payload' => [
            '_status' => 'ai_generation_failed',
            '_error' => 'JSON inválido',
        ],
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Chatbox',
        'description' => 'Módulo padrão',
        'status' => 'completed',
        'prd_payload' => [
            'source' => StandardProjectModuleService::SOURCE,
            'standard_module' => true,
        ],
    ]);

    (new ReconcileProjectCascadeJob($project))->handle();

    Queue::assertPushed(CascadeModulePrdJob::class, 2);
    Queue::assertPushed(
        CascadeModulePrdJob::class,
        fn (CascadeModulePrdJob $job): bool => $job->module->id === $missing->id
    );
    Queue::assertPushed(
        CascadeModulePrdJob::class,
        fn (CascadeModulePrdJob $job): bool => $job->module->id === $failed->id
    );
    Queue::assertPushed(SyncProjectRepositoryJob::class);
});
