<?php

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\StandardProjectModuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ScaffoldProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public Project $project,
        public string $dbPassword,
    ) {
        $this->onQueue('default');
    }

    public function handle(StandardProjectModuleService $standardModules): void
    {
        $script = '/var/www/html/projetos/ai-dev/instalar_projeto.sh';
        $name = $this->project->name;
        $projectPath = "/var/www/html/projetos/{$name}";

        Log::info("ScaffoldProjectJob: Iniciando scaffold do projeto '{$name}'");

        $standardModules->syncProject($this->project);

        $result = Process::timeout(600)->run(
            "bash {$script} ".escapeshellarg($name).' '.escapeshellarg($this->dbPassword)
        );

        if ($result->successful()) {
            $missing = Project::targetScaffoldMissingReasonsForPath($projectPath);

            if ($missing !== []) {
                $this->project->forceFill([
                    'local_path' => $projectPath,
                    'status' => ProjectStatus::ScaffoldFailed,
                ])->save();

                Log::error("ScaffoldProjectJob: Scaffold incompleto para '{$name}'", [
                    'missing' => $missing,
                    'stdout' => $result->output(),
                    'stderr' => $result->errorOutput(),
                ]);

                throw new \RuntimeException('Scaffold incompleto: '.implode('; ', $missing));
            }

            $this->project->update([
                'local_path' => $projectPath,
                'status' => ProjectStatus::Active,
            ]);

            SyncProjectRepositoryJob::dispatch($this->project->fresh());

            Log::info("ScaffoldProjectJob: Projeto '{$name}' criado com sucesso");
        } else {
            $this->project->forceFill([
                'local_path' => $projectPath,
                'status' => ProjectStatus::ScaffoldFailed,
            ])->save();

            Log::error("ScaffoldProjectJob: Falha ao criar projeto '{$name}'", [
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
                'stdout' => $result->output(),
            ]);

            throw new \RuntimeException(
                'Falha ao executar instalar_projeto.sh: '.$result->errorOutput()
            );
        }
    }
}
