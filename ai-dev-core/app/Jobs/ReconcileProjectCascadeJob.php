<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Services\StandardProjectModuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReconcileProjectCascadeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int MAX_ROUNDS = 48;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public Project $project,
        public int $round = 1,
    ) {
        $this->onQueue('orchestrator');
    }

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    public function handle(): void
    {
        $project = Project::query()
            ->with(['modules.children', 'modules.parent', 'modules.tasks'])
            ->find($this->project->id);

        if (! $project || ! $project->isBlueprintApproved()) {
            return;
        }

        $candidates = $this->candidates($project);

        foreach ($candidates as $module) {
            CascadeModulePrdJob::dispatch($module);
        }

        SyncProjectRepositoryJob::dispatch($project->fresh());

        Log::info("ReconcileProjectCascadeJob: {$candidates->count()} módulo(s) pendente(s) reencaminhado(s)", [
            'project' => $project->name,
            'round' => $this->round,
        ]);

        if ($candidates->isNotEmpty() && $this->round < self::MAX_ROUNDS) {
            self::dispatch($project->fresh(), $this->round + 1)
                ->delay(now()->addMinutes(10));
        }
    }

    /**
     * @return Collection<int, ProjectModule>
     */
    private function candidates(Project $project): Collection
    {
        return $project->modules
            ->reject(fn (ProjectModule $module): bool => $this->isStandardModule($module))
            ->filter(fn (ProjectModule $module): bool => $this->needsCascade($module))
            ->sortBy(fn (ProjectModule $module): string => sprintf(
                '%03d-%s-%s',
                $this->depth($module),
                (string) $module->created_at,
                $module->id,
            ))
            ->values();
    }

    private function needsCascade(ProjectModule $module): bool
    {
        $prd = $module->prd_payload;

        if (empty($prd) || ! empty($prd['_status'] ?? null)) {
            return true;
        }

        if ($module->children->isNotEmpty()) {
            return false;
        }

        return $module->tasks->isEmpty();
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

    private function depth(ProjectModule $module): int
    {
        $depth = 0;
        $parent = $module->parent;

        while ($parent !== null) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }
}
