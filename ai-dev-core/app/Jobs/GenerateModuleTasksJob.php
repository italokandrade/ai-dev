<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\ModuleTaskPlannerService;
use App\Services\ProjectPlanningScopeService;
use App\Services\StandardProjectModuleService;
use App\Support\PlanningLimits;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateModuleTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public ProjectModule $module,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $this->module->refresh()->loadMissing('project');

        Log::info("GenerateModuleTasksJob: Criando tasks para '{$this->module->name}'");

        $prd = $this->module->prd_payload;

        if (empty($prd)) {
            Log::info('GenerateModuleTasksJob: Nenhum PRD encontrado.');

            return;
        }

        if ($this->isStandardModulePrd($prd)) {
            Log::info("GenerateModuleTasksJob: '{$this->module->name}' é módulo padrão do AI-Dev. Tasks não serão geradas.");

            return;
        }

        if ($this->module->tasks()->exists()) {
            Log::info("GenerateModuleTasksJob: '{$this->module->name}' já possui tasks. Nada a fazer.");

            return;
        }

        if (PlanningLimits::deferTaskGenerationUntilProjectPrdsComplete() && $this->projectHasPendingPlanningPrds()) {
            Log::info("GenerateModuleTasksJob: Tasks de '{$this->module->name}' adiadas até todos os PRDs de módulos/submódulos ficarem prontos.");

            return;
        }

        $planner = app(ModuleTaskPlannerService::class);
        $tasks = $planner->taskDefinitions(
            $this->module,
            $prd,
            app(ProjectPlanningScopeService::class)->taskLimit($this->module->project),
        );

        $created = 0;

        foreach ($tasks as $taskData) {
            Task::create([
                'project_id' => $this->module->project_id,
                'module_id' => $this->module->id,
                'title' => $taskData['title'],
                'prd_payload' => $planner->taskPrdPayload($this->module->fresh(['project']), $taskData, $prd),
                'status' => TaskStatus::Pending,
                'priority' => $taskData['priority'],
                'source' => $taskData['source'],
                'max_retries' => 3,
            ]);
            $created++;
        }

        if ($created > 0) {
            SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
        }

        Log::info("GenerateModuleTasksJob: {$created} tasks criadas para '{$this->module->name}'");
    }

    private function isStandardModulePrd(mixed $prd): bool
    {
        return is_array($prd)
            && (
                ($prd['standard_module'] ?? false) === true
                || ($prd['source'] ?? null) === StandardProjectModuleService::SOURCE
            );
    }

    private function projectHasPendingPlanningPrds(): bool
    {
        $project = $this->module->project->fresh(['modules']);

        if ($project === null) {
            return false;
        }

        return $project->modules
            ->reject(function (ProjectModule $module): bool {
                $prd = $module->prd_payload;

                return is_array($prd)
                    && (
                        ($prd['standard_module'] ?? false) === true
                        || ($prd['source'] ?? null) === StandardProjectModuleService::SOURCE
                    );
            })
            ->contains(function (ProjectModule $module): bool {
                $prd = $module->prd_payload;

                return empty($prd) || ! empty($prd['_status'] ?? null);
            });
    }
}
