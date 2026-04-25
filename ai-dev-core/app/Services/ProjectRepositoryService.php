<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Subtask;
use App\Models\Task;
use BackedEnum;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ProjectRepositoryService
{
    public const string ARTIFACTS_DIR = '.ai-dev';

    private const string GIT_USER_NAME = 'AI-Dev Agent';

    private const string GIT_USER_EMAIL = 'ai-dev-agent@localhost';

    public function normalizeRemoteUrl(?string $repository): ?string
    {
        $repository = trim((string) $repository);

        if ($repository === '') {
            return null;
        }

        if (Str::startsWith($repository, ['git@', 'ssh://', 'file://', '/'])) {
            return $repository;
        }

        $repository = preg_replace('#^https?://github\.com/#', '', $repository) ?? $repository;
        $repository = preg_replace('#^github\.com/#', '', $repository) ?? $repository;
        $repository = trim($repository, '/');
        $repository = preg_replace('/\.git$/', '', $repository) ?? $repository;

        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) === 1) {
            return "git@github.com:{$repository}.git";
        }

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureRepository(Project $project, bool $requireRemote = false): array
    {
        $workDir = $this->workDir($project);
        $remoteUrl = $this->normalizeRemoteUrl($project->github_repo);

        if ($workDir === null) {
            return [
                'success' => false,
                'ready' => false,
                'skipped' => true,
                'reason' => 'local_path_missing',
                'remote_url' => $remoteUrl,
            ];
        }

        if (! is_dir($workDir)) {
            File::makeDirectory($workDir, 0755, true);
        }

        if ($requireRemote && $remoteUrl === null) {
            return [
                'success' => false,
                'ready' => false,
                'skipped' => true,
                'reason' => 'github_repo_missing',
                'work_dir' => $workDir,
            ];
        }

        $initialized = false;

        if (is_dir("{$workDir}/.git") && $this->gitRepositoryNeedsRepair($workDir)) {
            $repair = $this->quarantineGitRepository($workDir);

            if (! $repair) {
                return $this->failedResult(
                    'git_repair_quarantine_failed',
                    'Nao foi possivel colocar o repositorio Git local em quarentena para autoreparo.',
                    $workDir,
                    $remoteUrl,
                );
            }
        }

        if (! is_dir("{$workDir}/.git")) {
            if ($remoteUrl === null) {
                return [
                    'success' => false,
                    'ready' => false,
                    'skipped' => true,
                    'reason' => 'git_repository_missing',
                    'work_dir' => $workDir,
                ];
            }

            $init = $this->git($workDir, ['git', 'init'], 30);
            if (! $init->successful()) {
                return $this->failedResult('git_init_failed', $init->errorOutput(), $workDir, $remoteUrl);
            }

            $initialized = true;

            $this->git($workDir, ['git', 'branch', '-M', 'main'], 30);
            $this->excludeGitRepairQuarantine($workDir);
        }

        $this->ensureGitIdentity($workDir);

        $remoteConfigured = false;

        if ($remoteUrl !== null) {
            $currentRemote = $this->git($workDir, ['git', 'remote', 'get-url', 'origin'], 10);

            if (! $currentRemote->successful()) {
                $addRemote = $this->git($workDir, ['git', 'remote', 'add', 'origin', $remoteUrl], 10);
                if (! $addRemote->successful()) {
                    if (! $requireRemote) {
                        return $this->readyWithRemoteFailure(
                            'git_remote_add_failed',
                            $addRemote->errorOutput(),
                            $workDir,
                            $remoteUrl,
                            $initialized,
                        );
                    }

                    return $this->failedResult('git_remote_add_failed', $addRemote->errorOutput(), $workDir, $remoteUrl);
                }
            } elseif (trim($currentRemote->output()) !== $remoteUrl) {
                $setRemote = $this->git($workDir, ['git', 'remote', 'set-url', 'origin', $remoteUrl], 10);
                if (! $setRemote->successful()) {
                    if (! $requireRemote) {
                        return $this->readyWithRemoteFailure(
                            'git_remote_set_failed',
                            $setRemote->errorOutput(),
                            $workDir,
                            $remoteUrl,
                            $initialized,
                        );
                    }

                    return $this->failedResult('git_remote_set_failed', $setRemote->errorOutput(), $workDir, $remoteUrl);
                }
            }

            $remoteConfigured = true;

            if ($initialized) {
                $this->restoreRemoteBase($workDir);
            }
        }

        return [
            'success' => true,
            'ready' => true,
            'initialized' => $initialized,
            'remote_configured' => $remoteConfigured,
            'work_dir' => $workDir,
            'remote_url' => $remoteUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncDocumentation(Project $project, bool $push = true): array
    {
        $project = $this->reloadProject($project);
        $ensure = $this->ensureRepository($project, requireRemote: false);

        if (! ($ensure['ready'] ?? false)) {
            $this->logSkippedSync($project, $ensure);

            return $ensure + ['committed' => false, 'pushed' => false];
        }

        try {
            $files = $this->writeDocumentation($project);
        } catch (\Throwable $e) {
            Log::warning('ProjectRepositoryService: falha ao escrever artefatos do projeto', [
                'project' => $project->name,
                'work_dir' => $ensure['work_dir'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'committed' => false,
                'pushed' => false,
                'reason' => 'artifact_write_failed',
                'error' => $e->getMessage(),
                'repository' => $ensure,
            ];
        }

        $commit = $this->commitPaths(
            $project,
            [self::ARTIFACTS_DIR],
            'chore(ai-dev): sync project artifacts',
            push: false,
        );

        if (! ($commit['success'] ?? false)) {
            return $commit + [
                'files' => $files,
                'repository' => $ensure,
            ];
        }

        $pushResult = $push
            ? $this->push($project)
            : ['success' => true, 'skipped' => true, 'reason' => 'push_disabled', 'pushed' => false];
        $pushFailed = $push && ! ($pushResult['success'] ?? false);

        return [
            ...$commit,
            'success' => true,
            'pushed' => (bool) ($pushResult['pushed'] ?? false),
            'push_failed' => $pushFailed,
            'reason' => $pushFailed ? 'push_failed' : ($commit['reason'] ?? $pushResult['reason'] ?? null),
            'push' => $pushResult,
            'files' => $files,
            'repository' => $ensure,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commitPendingChanges(Project $project, string $message, bool $push = true): array
    {
        $project = $this->reloadProject($project);
        $ensure = $this->ensureRepository($project);

        if (! ($ensure['ready'] ?? false)) {
            return $ensure + ['committed' => false, 'pushed' => false];
        }

        $workDir = $ensure['work_dir'];
        $status = $this->git($workDir, ['git', 'status', '--porcelain'], 10);

        if (! $status->successful()) {
            return $this->failedResult('git_status_failed', $status->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
        }

        if (trim($status->output()) === '') {
            $hash = $this->currentHash($workDir);
            $pushResult = $push ? $this->push($project) : ['success' => true, 'skipped' => true, 'reason' => 'push_disabled'];

            return [
                'success' => true,
                'committed' => false,
                'pushed' => (bool) ($pushResult['pushed'] ?? false),
                'commit_hash' => $hash,
                'push' => $pushResult,
                'repository' => $ensure,
            ];
        }

        $add = $this->git($workDir, ['git', 'add', '-A'], 30);
        if (! $add->successful()) {
            return $this->failedResult('git_add_failed', $add->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
        }

        $commit = $this->git($workDir, ['git', 'commit', '-m', $message], 60);
        if (! $commit->successful()) {
            return $this->failedResult('git_commit_failed', $commit->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
        }

        $hash = $this->currentHash($workDir);
        $pushResult = $push ? $this->push($project) : ['success' => true, 'skipped' => true, 'reason' => 'push_disabled'];

        return [
            'success' => (bool) ($pushResult['success'] ?? false),
            'committed' => true,
            'pushed' => (bool) ($pushResult['pushed'] ?? false),
            'commit_hash' => $hash,
            'output' => mb_substr($commit->output(), 0, 5000),
            'error' => mb_substr($commit->errorOutput(), 0, 5000),
            'push' => $pushResult,
            'repository' => $ensure,
        ];
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, mixed>
     */
    public function commitPaths(Project $project, array $paths, string $message, bool $push = true): array
    {
        $ensure = $this->ensureRepository($project, requireRemote: $push);

        if (! ($ensure['ready'] ?? false)) {
            return $ensure + ['committed' => false, 'pushed' => false];
        }

        $workDir = $ensure['work_dir'];

        foreach ($paths as $path) {
            $add = $this->git($workDir, ['git', 'add', '-f', '--', $path], 30);
            if (! $add->successful()) {
                return $this->failedResult('git_add_failed', $add->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
            }
        }

        $diff = $this->git($workDir, ['git', 'diff', '--cached', '--quiet', '--', ...$paths], 30);
        if ($diff->exitCode() > 1) {
            return $this->failedResult('git_diff_failed', $diff->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
        }

        $hasStagedChanges = $diff->exitCode() === 1;

        if ($hasStagedChanges) {
            $commit = $this->git($workDir, ['git', 'commit', '-m', $message, '--', ...$paths], 60);
            if (! $commit->successful()) {
                return $this->failedResult('git_commit_failed', $commit->errorOutput(), $workDir, $ensure['remote_url'] ?? null);
            }
        }

        $hash = $this->currentHash($workDir);
        $pushResult = $push ? $this->push($project) : ['success' => true, 'skipped' => true, 'reason' => 'push_disabled'];

        return [
            'success' => (bool) ($pushResult['success'] ?? true),
            'committed' => $hasStagedChanges,
            'pushed' => (bool) ($pushResult['pushed'] ?? false),
            'commit_hash' => $hash,
            'push' => $pushResult,
            'repository' => $ensure,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function push(Project $project): array
    {
        $project = $this->reloadProject($project);
        $ensure = $this->ensureRepository($project, requireRemote: true);

        if (! ($ensure['ready'] ?? false)) {
            return $ensure + ['pushed' => false];
        }

        $workDir = $ensure['work_dir'];

        if ($this->currentHash($workDir) === null) {
            return [
                'success' => true,
                'pushed' => false,
                'skipped' => true,
                'reason' => 'no_commits',
                'repository' => $ensure,
            ];
        }

        $push = $this->git($workDir, ['git', 'push', '-u', 'origin', 'HEAD'], 180);

        if ($push->successful()) {
            return [
                'success' => true,
                'pushed' => true,
                'output' => mb_substr($push->output(), 0, 5000),
                'error' => mb_substr($push->errorOutput(), 0, 5000),
                'repository' => $ensure,
            ];
        }

        $branch = trim($this->git($workDir, ['git', 'branch', '--show-current'], 10)->output());

        if ($branch !== '') {
            $pull = $this->git($workDir, ['git', 'pull', '--rebase', 'origin', $branch], 180);

            if ($pull->successful()) {
                $retry = $this->git($workDir, ['git', 'push', '-u', 'origin', 'HEAD'], 180);

                return [
                    'success' => $retry->successful(),
                    'pushed' => $retry->successful(),
                    'retried_after_rebase' => true,
                    'output' => mb_substr($retry->output(), 0, 5000),
                    'error' => mb_substr($retry->errorOutput(), 0, 5000),
                    'repository' => $ensure,
                ];
            }
        }

        return [
            'success' => false,
            'pushed' => false,
            'reason' => 'git_push_failed',
            'output' => mb_substr($push->output(), 0, 5000),
            'error' => mb_substr($push->errorOutput(), 0, 5000),
            'repository' => $ensure,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function writeDocumentation(Project $project): array
    {
        $workDir = $this->workDir($project);

        if ($workDir === null) {
            return [];
        }

        $artifactsDir = "{$workDir}/".self::ARTIFACTS_DIR;
        $stagingDir = "{$workDir}/.".self::ARTIFACTS_DIR.'-tmp-'.Str::uuid();
        $preservedArchitectureArtifacts = $this->preservedArchitectureArtifacts($artifactsDir);
        File::ensureDirectoryExists($stagingDir);
        File::ensureDirectoryExists("{$stagingDir}/".ProjectArchitectureArtifactService::ARCHITECTURE_DIR);
        File::ensureDirectoryExists("{$stagingDir}/modules");
        File::ensureDirectoryExists("{$stagingDir}/tasks");
        File::ensureDirectoryExists("{$stagingDir}/subtasks");

        $written = [];

        try {
            $documents = [
                'README.md' => $this->readmeMarkdown($project),
                'PROJECT.md' => $this->projectMarkdown($project),
                'project.json' => $this->json($this->projectPayload($project)),
                'manifest.json' => $this->json($this->manifestPayload($project)),
            ];

            if (is_array($project->prd_payload) && $project->prd_payload !== []) {
                $documents['prd-master.md'] = $this->payloadMarkdown(
                    $project->prd_payload,
                    "{$project->name} - PRD Master",
                    'Project PRD',
                );
                $documents['prd-master.json'] = $this->json($project->prd_payload);
            }

            if (is_array($project->blueprint_payload) && $project->blueprint_payload !== []) {
                $documents['blueprint-global.md'] = $this->payloadMarkdown(
                    $project->blueprint_payload,
                    "{$project->name} - Blueprint Tecnico Global",
                    'Project Blueprint',
                );
                $documents['blueprint-global.json'] = $this->json($project->blueprint_payload);

                $documents = array_merge(
                    $documents,
                    app(ProjectArchitectureArtifactService::class)->documents($project),
                );
            }

            $documents = array_merge($documents, $preservedArchitectureArtifacts);

            foreach ($documents as $relativePath => $contents) {
                $this->putArtifact($stagingDir, $relativePath, $contents, $written);
            }

            foreach ($project->modules->sortBy(fn (ProjectModule $module): string => $this->modulePath($module)) as $module) {
                $base = 'modules/'.$this->artifactSlug($this->modulePath($module), $module->id);
                $this->putArtifact($stagingDir, "{$base}.md", $this->moduleMarkdown($module), $written);
                $this->putArtifact($stagingDir, "{$base}.json", $this->json($this->modulePayload($module)), $written);
            }

            foreach ($project->tasks->sortBy('created_at') as $task) {
                $base = 'tasks/'.$this->artifactSlug($task->title, $task->id);
                $this->putArtifact($stagingDir, "{$base}.md", $this->taskMarkdown($task), $written);
                $this->putArtifact($stagingDir, "{$base}.json", $this->json($this->taskPayload($task)), $written);

                foreach ($task->subtasks->sortBy('execution_order') as $subtask) {
                    $subtaskBase = 'subtasks/'.$this->artifactSlug($subtask->title, $subtask->id);
                    $this->putArtifact($stagingDir, "{$subtaskBase}.md", $this->subtaskMarkdown($subtask), $written);
                    $this->putArtifact($stagingDir, "{$subtaskBase}.json", $this->json($this->subtaskPayload($subtask)), $written);
                }
            }

            $this->replaceArtifactsDirectory($workDir, $artifactsDir, $stagingDir);
        } catch (\Throwable $e) {
            File::deleteDirectory($stagingDir);

            throw $e;
        }

        return $written;
    }

    /**
     * @param  array<int, string>  $written
     */
    private function putArtifact(string $baseDir, string $relativePath, string $contents, array &$written): void
    {
        $path = "{$baseDir}/{$relativePath}";

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        $written[] = self::ARTIFACTS_DIR.'/'.$relativePath;
    }

    private function replaceArtifactsDirectory(string $workDir, string $artifactsDir, string $stagingDir): void
    {
        if (File::exists($artifactsDir)) {
            $quarantineRoot = "{$workDir}/.git/ai-dev-quarantine";
            File::ensureDirectoryExists($quarantineRoot);

            $quarantinePath = "{$quarantineRoot}/".self::ARTIFACTS_DIR.'-'.Str::uuid();

            if (! @rename($artifactsDir, $quarantinePath)) {
                File::deleteDirectory($artifactsDir);
            }
        }

        if (! @rename($stagingDir, $artifactsDir)) {
            File::moveDirectory($stagingDir, $artifactsDir, true);
        }
    }

    /**
     * @return array<string, string>
     */
    private function preservedArchitectureArtifacts(string $artifactsDir): array
    {
        $preserved = [];

        foreach (['erd-physical.txt', 'erd-physical.svg'] as $file) {
            $path = "{$artifactsDir}/".ProjectArchitectureArtifactService::ARCHITECTURE_DIR."/{$file}";
            if (is_file($path)) {
                try {
                    $preserved[ProjectArchitectureArtifactService::ARCHITECTURE_DIR."/{$file}"] = (string) File::get($path);
                } catch (\Throwable) {
                    // Artefatos fisicos opcionais podem estar com permissao externa; a sincronizacao principal deve continuar.
                }
            }
        }

        return $preserved;
    }

    private function reloadProject(Project $project): Project
    {
        return Project::query()
            ->with([
                'features',
                'modules.parent',
                'modules.tasks.subtasks',
                'tasks.subtasks',
            ])
            ->find($project->id) ?? $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestPayload(Project $project): array
    {
        return [
            'source' => 'ai-dev-core',
            'project_id' => $project->id,
            'project_name' => $project->name,
            'github_repo' => $project->github_repo,
            'remote_url' => $this->normalizeRemoteUrl($project->github_repo),
            'local_path' => $project->local_path,
            'artifacts_directory' => self::ARTIFACTS_DIR,
            'managed_artifacts' => [
                'PROJECT.md',
                'project.json',
                'prd-master.md',
                'prd-master.json',
                'blueprint-global.md',
                'blueprint-global.json',
                'architecture/README.md',
                'architecture/domain-model.mmd',
                'architecture/domain-model.md',
                'architecture/domain-model.json',
                'architecture/checkpoint-protocol.md',
                'architecture/erd-physical.txt',
                'architecture/erd-physical.svg',
                'modules/*.md',
                'modules/*.json',
                'tasks/*.md',
                'tasks/*.json',
                'subtasks/*.md',
                'subtasks/*.json',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $this->enumValue($project->status),
            'github_repo' => $project->github_repo,
            'local_path' => $project->local_path,
            'prd_approved_at' => $project->prd_approved_at?->toISOString(),
            'blueprint_approved_at' => $project->blueprint_approved_at?->toISOString(),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
            'features' => $project->features
                ->sortBy('created_at')
                ->map(fn ($feature): array => [
                    'id' => $feature->id,
                    'type' => $feature->type,
                    'title' => $feature->title,
                    'description' => $feature->description,
                ])
                ->values()
                ->all(),
            'prd_payload' => $project->prd_payload,
            'blueprint_payload' => $project->blueprint_payload,
            'modules' => $project->modules
                ->sortBy(fn (ProjectModule $module): string => $this->modulePath($module))
                ->map(fn (ProjectModule $module): array => $this->modulePayload($module))
                ->values()
                ->all(),
            'tasks' => $project->tasks
                ->sortBy('created_at')
                ->map(fn (Task $task): array => $this->taskPayload($task))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modulePayload(ProjectModule $module): array
    {
        return [
            'id' => $module->id,
            'project_id' => $module->project_id,
            'parent_id' => $module->parent_id,
            'path' => $this->modulePath($module),
            'name' => $module->name,
            'description' => $module->description,
            'status' => $this->enumValue($module->status),
            'priority' => $this->enumValue($module->priority),
            'dependencies' => $module->dependencies,
            'progress_percentage' => $module->progress_percentage,
            'prd_payload' => $module->prd_payload,
            'blueprint_payload' => $module->blueprint_payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'module_id' => $task->module_id,
            'title' => $task->title,
            'status' => $this->enumValue($task->status),
            'priority' => $this->enumValue($task->priority),
            'source' => $this->enumValue($task->source),
            'commit_hash' => $task->commit_hash,
            'prd_payload' => $task->prd_payload,
            'subtasks' => $task->subtasks
                ->sortBy('execution_order')
                ->map(fn (Subtask $subtask): array => $this->subtaskPayload($subtask))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subtaskPayload(Subtask $subtask): array
    {
        return [
            'id' => $subtask->id,
            'task_id' => $subtask->task_id,
            'title' => $subtask->title,
            'status' => $this->enumValue($subtask->status),
            'assigned_agent' => $subtask->assigned_agent,
            'execution_order' => $subtask->execution_order,
            'commit_hash' => $subtask->commit_hash,
            'file_locks' => $subtask->file_locks,
            'sub_prd_payload' => $subtask->sub_prd_payload,
        ];
    }

    private function readmeMarkdown(Project $project): string
    {
        return implode("\n", [
            '# AI-Dev Project Documentation',
            '',
            "Projeto alvo: {$project->name}",
            '',
            'Este diretorio e gerenciado pelo ai-dev-core. Ele guarda a documentacao operacional, PRDs, blueprint tecnico, modulos, tasks e subtasks sincronizados a partir do cadastro do projeto.',
            '',
            'Arquivos principais:',
            '',
            '- `PROJECT.md`: resumo humano do projeto.',
            '- `project.json`: estado estruturado completo exportado do ai-dev-core.',
            '- `prd-master.*`: PRD Master do projeto.',
            '- `blueprint-global.*`: Blueprint Tecnico Global.',
            '- `architecture/`: MER em Mermaid, dominio normalizado e protocolo de checkpoint fisico.',
            '- `modules/`: PRDs e blueprints por modulo/submodulo.',
            '- `tasks/` e `subtasks/`: PRDs operacionais usados pelos agentes.',
            '',
        ]);
    }

    private function projectMarkdown(Project $project): string
    {
        $lines = [
            "# {$project->name}",
            '',
            '| Campo | Valor |',
            '| --- | --- |',
            '| Status | '.$this->tableValue($this->enumValue($project->status)).' |',
            '| GitHub | '.$this->tableValue($project->github_repo ?: 'Nao informado').' |',
            '| Remote | '.$this->tableValue($this->normalizeRemoteUrl($project->github_repo) ?: 'Nao informado').' |',
            '| Caminho local | '.$this->tableValue($project->local_path ?: 'Nao informado').' |',
            '| PRD aprovado em | '.$this->tableValue($project->prd_approved_at?->toISOString() ?: 'Nao aprovado').' |',
            '| Blueprint aprovado em | '.$this->tableValue($project->blueprint_approved_at?->toISOString() ?: 'Nao aprovado').' |',
            '',
        ];

        if ($project->description) {
            $lines[] = '## Descricao';
            $lines[] = '';
            $lines[] = $project->description;
            $lines[] = '';
        }

        $lines[] = '## Modulos';
        $lines[] = '';
        $lines[] = '| Modulo | Status | Prioridade |';
        $lines[] = '| --- | --- | --- |';

        foreach ($project->modules->sortBy(fn (ProjectModule $module): string => $this->modulePath($module)) as $module) {
            $lines[] = '| '.$this->tableValue($this->modulePath($module)).' | '
                .$this->tableValue($this->enumValue($module->status)).' | '
                .$this->tableValue($this->enumValue($module->priority)).' |';
        }

        $lines[] = '';
        $lines[] = '## Tasks';
        $lines[] = '';
        $lines[] = '| Task | Status | Commit |';
        $lines[] = '| --- | --- | --- |';

        foreach ($project->tasks->sortBy('created_at') as $task) {
            $lines[] = '| '.$this->tableValue($task->title).' | '
                .$this->tableValue($this->enumValue($task->status)).' | '
                .$this->tableValue($task->commit_hash ?: '').' |';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function moduleMarkdown(ProjectModule $module): string
    {
        $title = $this->modulePath($module);
        $payload = [
            'Resumo' => [
                'status' => $this->enumValue($module->status),
                'priority' => $this->enumValue($module->priority),
                'progress_percentage' => $module->progress_percentage,
                'description' => $module->description,
            ],
            'prd_payload' => $module->prd_payload,
            'blueprint_payload' => $module->blueprint_payload,
        ];

        return $this->payloadMarkdown($payload, $title, 'Module');
    }

    private function taskMarkdown(Task $task): string
    {
        return $this->payloadMarkdown($this->taskPayload($task), $task->title, 'Task');
    }

    private function subtaskMarkdown(Subtask $subtask): string
    {
        return $this->payloadMarkdown($this->subtaskPayload($subtask), $subtask->title, 'Subtask');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadMarkdown(array $payload, string $title, string $type): string
    {
        $lines = [
            "# {$title}",
            '',
            "Tipo: {$type}",
            '',
        ];

        foreach (['title', 'objective', 'scope', 'summary'] as $key) {
            if (! empty($payload[$key]) && is_scalar($payload[$key])) {
                $lines[] = '## '.Str::headline($key);
                $lines[] = '';
                $lines[] = (string) $payload[$key];
                $lines[] = '';
            }
        }

        $lines[] = '## Payload';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = $this->json($payload);
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function modulePath(ProjectModule $module): string
    {
        $segments = [$module->name];
        $parent = $module->parent;

        while ($parent) {
            array_unshift($segments, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' / ', $segments);
    }

    private function artifactSlug(string $title, string $id): string
    {
        $slug = Str::slug($title);

        if ($slug === '') {
            $slug = 'artifact';
        }

        return $slug.'-'.Str::lower(Str::substr($id, 0, 8));
    }

    private function json(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
    }

    private function tableValue(mixed $value): string
    {
        return str_replace(["\n", '|'], [' ', '\|'], trim((string) $value));
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function workDir(Project $project): ?string
    {
        $path = trim((string) $project->local_path);

        return $path !== '' ? rtrim($path, '/') : null;
    }

    private function currentHash(string $workDir): ?string
    {
        $result = $this->git($workDir, ['git', 'rev-parse', '--verify', 'HEAD'], 10);
        $hash = trim($result->output());

        return $result->successful() && $hash !== '' ? $hash : null;
    }

    private function ensureGitIdentity(string $workDir): void
    {
        $name = trim($this->git($workDir, ['git', 'config', 'user.name'], 10)->output());
        if ($name === '') {
            $this->git($workDir, ['git', 'config', 'user.name', self::GIT_USER_NAME], 10);
        }

        $email = trim($this->git($workDir, ['git', 'config', 'user.email'], 10)->output());
        if ($email === '') {
            $this->git($workDir, ['git', 'config', 'user.email', self::GIT_USER_EMAIL], 10);
        }
    }

    private function gitRepositoryNeedsRepair(string $workDir): bool
    {
        $gitDir = "{$workDir}/.git";

        foreach ([$gitDir, "{$gitDir}/objects", "{$gitDir}/refs", "{$gitDir}/index", "{$gitDir}/config", "{$gitDir}/HEAD", "{$gitDir}/packed-refs"] as $path) {
            if (file_exists($path) && ! is_writable($path)) {
                return true;
            }
        }

        return $this->gitPathContainsPermissionDrift("{$gitDir}/objects")
            || $this->gitPathContainsPermissionDrift("{$gitDir}/refs", filesMatter: true)
            || $this->gitPathContainsPermissionDrift("{$gitDir}/logs", filesMatter: true);
    }

    private function gitPathContainsPermissionDrift(string $path, bool $filesMatter = false): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (($item->isDir() || $filesMatter) && ! is_writable($item->getPathname())) {
                return true;
            }
        }

        return false;
    }

    private function quarantineGitRepository(string $workDir): bool
    {
        $quarantinePath = "{$workDir}/.git-quarantine-".Str::uuid();

        if (! @rename("{$workDir}/.git", $quarantinePath)) {
            Log::warning('ProjectRepositoryService: falha ao colocar repositorio Git local em quarentena', [
                'work_dir' => $workDir,
                'quarantine_path' => $quarantinePath,
            ]);

            return false;
        }

        Log::warning('ProjectRepositoryService: repositorio Git local colocado em quarentena para autoreparo', [
            'work_dir' => $workDir,
            'quarantine_path' => $quarantinePath,
        ]);

        return true;
    }

    private function excludeGitRepairQuarantine(string $workDir): void
    {
        $exclude = "{$workDir}/.git/info/exclude";
        $line = '.git-quarantine-*';
        $contents = is_file($exclude) ? (string) File::get($exclude) : '';

        if (! str_contains($contents, $line)) {
            File::append($exclude, "\n{$line}\n");
        }
    }

    private function restoreRemoteBase(string $workDir): void
    {
        $fetch = $this->git($workDir, ['git', 'fetch', 'origin', 'main'], 120);

        if (! $fetch->successful()) {
            return;
        }

        $this->git($workDir, ['git', 'reset', '--mixed', 'origin/main'], 60);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function git(string $workDir, array $command, int $timeout)
    {
        if (($command[0] ?? null) === 'git') {
            array_splice($command, 1, 0, ['-c', "safe.directory={$workDir}"]);
        }

        return Process::path($workDir)->timeout($timeout)->run($command);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedResult(string $reason, string $error, ?string $workDir, ?string $remoteUrl): array
    {
        return [
            'success' => false,
            'ready' => false,
            'reason' => $reason,
            'work_dir' => $workDir,
            'remote_url' => $remoteUrl,
            'error' => mb_substr($error, 0, 5000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readyWithRemoteFailure(string $reason, string $error, string $workDir, string $remoteUrl, bool $initialized): array
    {
        return [
            'success' => true,
            'ready' => true,
            'initialized' => $initialized,
            'remote_configured' => false,
            'remote_error_reason' => $reason,
            'remote_error' => mb_substr($error, 0, 5000),
            'work_dir' => $workDir,
            'remote_url' => $remoteUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function logSkippedSync(Project $project, array $result): void
    {
        if (($result['reason'] ?? null) === 'github_repo_missing') {
            return;
        }

        Log::info('ProjectRepositoryService: documentacao nao sincronizada', [
            'project' => $project->name,
            'reason' => $result['reason'] ?? null,
        ]);
    }
}
