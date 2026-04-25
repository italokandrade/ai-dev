<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\ProjectRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProjectRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public Project $project,
        public bool $push = true,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(ProjectRepositoryService $repository): void
    {
        $result = $repository->syncDocumentation($this->project->fresh(), $this->push);

        if (! ($result['success'] ?? false) && ! ($result['skipped'] ?? false)) {
            Log::warning('SyncProjectRepositoryJob: falha ao sincronizar repositorio do projeto', [
                'project' => $this->project->name,
                'reason' => $result['reason'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
        }
    }
}
