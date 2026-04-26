<?php

use App\Models\Project;
use App\Models\ProjectModule;
use App\Services\ProjectRepositoryService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

test('project repository service normalizes github repository addresses', function () {
    $service = app(ProjectRepositoryService::class);

    expect($service->normalizeRemoteUrl('italokandrade/juridic-tech-pro'))
        ->toBe('git@github.com:italokandrade/juridic-tech-pro.git')
        ->and($service->normalizeRemoteUrl('https://github.com/italokandrade/juridic-tech-pro.git'))
        ->toBe('git@github.com:italokandrade/juridic-tech-pro.git')
        ->and($service->normalizeRemoteUrl('github.com/italokandrade/juridic-tech-pro'))
        ->toBe('git@github.com:italokandrade/juridic-tech-pro.git')
        ->and($service->normalizeRemoteUrl('git@github.com:italokandrade/juridic-tech-pro.git'))
        ->toBe('git@github.com:italokandrade/juridic-tech-pro.git');
});

test('project repository service writes prd documentation, commits, and pushes', function () {
    $baseDir = storage_path('framework/testing/project-repository-service-'.Str::uuid());
    $workDir = "{$baseDir}/work";
    $remoteDir = "{$baseDir}/remote.git";

    File::ensureDirectoryExists($workDir);
    File::ensureDirectoryExists($remoteDir);

    Process::path($remoteDir)->run(['git', 'init', '--bare']);

    $project = Project::create([
        'name' => 'repository-sync-project',
        'description' => 'Projeto usado para validar sincronizacao Git.',
        'github_repo' => $remoteDir,
        'local_path' => $workDir,
        'status' => 'active',
        'prd_payload' => [
            'title' => 'PRD Master Teste',
            'objective' => 'Salvar PRDs no repositorio do projeto.',
            'modules' => [
                ['name' => 'Atendimento', 'description' => 'Fluxo principal'],
            ],
        ],
        'blueprint_payload' => [
            'title' => 'Blueprint Teste',
            'summary' => 'MER inicial do projeto.',
            'domain_model' => [
                'entities' => [
                    ['name' => 'clientes', 'description' => 'Clientes atendidos'],
                    ['name' => 'processos', 'description' => 'Processos vinculados'],
                ],
                'relationships' => [
                    [
                        'source' => 'clientes',
                        'target' => 'processos',
                        'type' => 'one_to_many',
                        'foreign_key' => 'cliente_id',
                        'description' => 'Cliente possui processos',
                    ],
                ],
            ],
        ],
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Atendimento',
        'description' => 'Fluxo principal',
        'status' => 'planned',
        'prd_payload' => [
            'title' => 'Atendimento - PRD Tecnico',
            'objective' => 'Implementar atendimento.',
        ],
    ]);

    try {
        $result = app(ProjectRepositoryService::class)->syncDocumentation($project, push: true);

        $origin = Process::path($workDir)->run(['git', 'remote', 'get-url', 'origin']);
        $remoteHead = Process::run(['git', '--git-dir', $remoteDir, 'rev-parse', '--verify', 'refs/heads/main']);
        $trackedProjectIndex = Process::path($workDir)->run(['git', 'ls-files', '.ai-dev/project.json']);
        $projectIndex = json_decode(File::get("{$workDir}/.ai-dev/project.json"), true);

        expect($result['success'])->toBeTrue()
            ->and($result['committed'])->toBeTrue()
            ->and($result['pushed'])->toBeTrue()
            ->and(trim($origin->output()))->toBe($remoteDir)
            ->and(trim($trackedProjectIndex->output()))->toBe('.ai-dev/project.json')
            ->and($projectIndex['artifact_type'])->toBe('project_index')
            ->and($projectIndex)->not->toHaveKey('prd_payload')
            ->and($projectIndex)->not->toHaveKey('blueprint_payload')
            ->and($projectIndex['modules'][0])->not->toHaveKey('prd_payload')
            ->and(str_ends_with($projectIndex['modules'][0]['artifacts']['json'], '.json'))->toBeTrue()
            ->and(File::exists("{$workDir}/.ai-dev/prd-master.json"))->toBeTrue()
            ->and(File::get("{$workDir}/.ai-dev/prd-master.md"))->toContain('Salvar PRDs no repositorio do projeto')
            ->and(File::exists("{$workDir}/.ai-dev/architecture/domain-model.mmd"))->toBeTrue()
            ->and(File::get("{$workDir}/.ai-dev/architecture/domain-model.mmd"))->toContain('CLIENTES ||--o{ PROCESSOS')
            ->and(File::get("{$workDir}/.ai-dev/PROJECT.md"))->toContain('repository-sync-project')
            ->and($remoteHead->successful())->toBeTrue();
    } finally {
        File::deleteDirectory($baseDir);
    }
});

test('project repository service keeps local documentation commit when push fails', function () {
    $baseDir = storage_path('framework/testing/project-repository-push-fails-'.Str::uuid());
    $workDir = "{$baseDir}/work";
    $missingRemote = "{$baseDir}/missing-remote.git";

    File::ensureDirectoryExists($workDir);

    $project = Project::create([
        'name' => 'repository-local-sync-project',
        'description' => 'Projeto usado para validar fallback local.',
        'github_repo' => $missingRemote,
        'local_path' => $workDir,
        'status' => 'active',
        'prd_payload' => [
            'title' => 'PRD Master Local',
            'objective' => 'Salvar documentação mesmo quando push falhar.',
            'modules' => [
                ['name' => 'Landing Page', 'description' => 'Página pública'],
            ],
        ],
    ]);

    try {
        $result = app(ProjectRepositoryService::class)->syncDocumentation($project, push: true);

        $localHead = Process::path($workDir)
            ->run(['git', '-c', "safe.directory={$workDir}", 'rev-parse', '--verify', 'HEAD']);

        expect($result['success'])->toBeTrue()
            ->and($result['committed'])->toBeTrue()
            ->and($result['pushed'])->toBeFalse()
            ->and($result['push_failed'])->toBeTrue()
            ->and(File::exists("{$workDir}/.ai-dev/prd-master.json"))->toBeTrue()
            ->and(File::get("{$workDir}/.ai-dev/prd-master.md"))->toContain('Salvar documentação mesmo quando push falhar')
            ->and($localHead->successful())->toBeTrue();
    } finally {
        File::deleteDirectory($baseDir);
    }
});

test('project repository service cleans stale ai dev staging directories during sync', function () {
    $baseDir = storage_path('framework/testing/project-repository-stale-staging-'.Str::uuid());
    $workDir = "{$baseDir}/work";
    $remoteDir = "{$baseDir}/remote.git";

    File::ensureDirectoryExists($workDir);
    File::ensureDirectoryExists($remoteDir);
    File::ensureDirectoryExists("{$workDir}/.ai-dev-tmp-stale");
    File::ensureDirectoryExists("{$workDir}/..ai-dev-tmp-legacy-stale");

    Process::path($remoteDir)->run(['git', 'init', '--bare']);

    $project = Project::create([
        'name' => 'repository-stale-staging-project',
        'description' => 'Projeto usado para validar limpeza de staging.',
        'github_repo' => $remoteDir,
        'local_path' => $workDir,
        'status' => 'active',
        'prd_payload' => [
            'title' => 'PRD Master Staging',
            'objective' => 'Sincronizar sem sobras temporarias.',
            'modules' => [
                ['name' => 'Landing Page', 'description' => 'Página pública'],
            ],
        ],
    ]);

    try {
        $result = app(ProjectRepositoryService::class)->syncDocumentation($project, push: false);

        expect($result['success'])->toBeTrue()
            ->and(File::exists("{$workDir}/.ai-dev-tmp-stale"))->toBeFalse()
            ->and(File::exists("{$workDir}/..ai-dev-tmp-legacy-stale"))->toBeFalse()
            ->and(glob("{$workDir}/.ai-dev-tmp-*"))->toBe([])
            ->and(glob("{$workDir}/..ai-dev-tmp-*"))->toBe([])
            ->and(File::exists("{$workDir}/.ai-dev/prd-master.json"))->toBeTrue();
    } finally {
        File::deleteDirectory($baseDir);
    }
});

test('project repository service clears stale git index locks before committing pending changes', function () {
    $baseDir = storage_path('framework/testing/project-repository-stale-lock-'.Str::uuid());
    $workDir = "{$baseDir}/work";
    $remoteDir = "{$baseDir}/remote.git";

    File::ensureDirectoryExists($workDir);
    File::ensureDirectoryExists($remoteDir);

    Process::path($remoteDir)->run(['git', 'init', '--bare']);

    $project = Project::create([
        'name' => 'repository-stale-lock-project',
        'description' => 'Projeto usado para validar limpeza de lock Git.',
        'github_repo' => $remoteDir,
        'local_path' => $workDir,
        'status' => 'active',
    ]);

    try {
        $repository = app(ProjectRepositoryService::class);
        $repository->syncDocumentation($project, push: false);

        File::put("{$workDir}/README-test.md", 'conteudo');
        File::put("{$workDir}/.git/index.lock", 'stale');
        touch("{$workDir}/.git/index.lock", time() - 180);

        $result = $repository->commitPendingChanges($project, 'test: commit after stale lock', push: false);

        expect($result['success'])->toBeTrue()
            ->and($result['committed'])->toBeTrue()
            ->and(File::exists("{$workDir}/.git/index.lock"))->toBeFalse();
    } finally {
        File::deleteDirectory($baseDir);
    }
});
