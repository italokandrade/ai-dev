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
                ],
                'relationships' => [],
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

        expect($result['success'])->toBeTrue()
            ->and($result['committed'])->toBeTrue()
            ->and($result['pushed'])->toBeTrue()
            ->and(trim($origin->output()))->toBe($remoteDir)
            ->and(File::exists("{$workDir}/.ai-dev/prd-master.json"))->toBeTrue()
            ->and(File::get("{$workDir}/.ai-dev/prd-master.md"))->toContain('Salvar PRDs no repositorio do projeto')
            ->and(File::get("{$workDir}/.ai-dev/PROJECT.md"))->toContain('repository-sync-project')
            ->and($remoteHead->successful())->toBeTrue();
    } finally {
        File::deleteDirectory($baseDir);
    }
});
