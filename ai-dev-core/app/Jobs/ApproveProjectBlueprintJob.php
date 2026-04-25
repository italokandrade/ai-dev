<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Services\StandardProjectModuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApproveProjectBlueprintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public Project $project,
        public bool $cascade = false,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $this->project->refresh();

        if (! $this->project->isPrdApproved()) {
            throw new \RuntimeException('O PRD Master precisa estar aprovado antes da aprovação do Blueprint.');
        }

        if (! $this->project->isBlueprintReady()) {
            throw new \RuntimeException('O Blueprint Técnico precisa estar pronto antes da aprovação.');
        }

        if (! $this->project->isBlueprintApproved()) {
            $this->project->approveBlueprint();
        }

        $this->project->createModulesFromPrd();

        $project = $this->project->fresh();

        SyncProjectRepositoryJob::dispatch($project);

        if ($this->cascade) {
            $modules = $project->modules()
                ->whereNull('parent_id')
                ->get()
                ->reject(fn (ProjectModule $module): bool => $this->isStandardModule($module))
                ->values();

            foreach ($modules as $module) {
                CascadeModulePrdJob::dispatch($module);
            }

            ReconcileProjectCascadeJob::dispatch($project)
                ->delay(now()->addMinutes(10));

            Log::info("ApproveProjectBlueprintJob: Cascata iniciada para '{$project->name}'", [
                'modules' => $modules->count(),
            ]);

            return;
        }

        Log::info("ApproveProjectBlueprintJob: Blueprint aprovado para '{$project->name}'");
    }

    private function isStandardModule(ProjectModule $module): bool
    {
        $prd = $module->prd_payload;

        return is_array($prd)
            && (
                ($prd['standard_module'] ?? false) === true
                || ($prd['source'] ?? null) === StandardProjectModuleService::SOURCE
            );
    }
}
