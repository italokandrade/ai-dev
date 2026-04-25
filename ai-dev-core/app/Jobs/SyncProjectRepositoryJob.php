<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\ProjectRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProjectRepositoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public Project $project,
        public bool $push = true,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(ProjectRepositoryService $repository): void
    {
        $result = $repository->syncDocumentation($this->project->fresh(), $this->push);

        if ($result['push_failed'] ?? false) {
            Log::warning('SyncProjectRepositoryJob: documentacao sincronizada localmente, mas push falhou', [
                'project' => $this->project->name,
                'reason' => $result['reason'] ?? null,
                'error' => $result['push']['error'] ?? $result['error'] ?? null,
            ]);

            return;
        }

        if (! ($result['success'] ?? false) && ! ($result['skipped'] ?? false)) {
            Log::warning('SyncProjectRepositoryJob: falha ao sincronizar repositorio do projeto', [
                'project' => $this->project->name,
                'reason' => $result['reason'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
        }
    }
}
