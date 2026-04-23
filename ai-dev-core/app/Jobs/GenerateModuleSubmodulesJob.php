<?php

namespace App\Jobs;

use App\Models\ProjectModule;
use App\Enums\ModuleStatus;
use App\Enums\Priority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateModuleSubmodulesJob implements ShouldQueue
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
        Log::info("GenerateModuleSubmodulesJob: Criando submódulos para '{$this->module->name}'");

        $prd = $this->module->prd_payload;

        if (empty($prd) || empty($prd['submodules'])) {
            Log::info("GenerateModuleSubmodulesJob: Nenhum submódulo definido no PRD.");
            return;
        }

        $created = 0;

        foreach ($prd['submodules'] as $submoduleData) {
            $priorityEnum = match ($submoduleData['priority'] ?? 'normal') {
                'high' => Priority::High,
                'medium' => Priority::Medium,
                default => Priority::Normal,
            };

            ProjectModule::create([
                'project_id' => $this->module->project_id,
                'parent_id' => $this->module->id,
                'name' => $submoduleData['name'],
                'description' => $submoduleData['description'] ?? '',
                'status' => ModuleStatus::Planned,
                'priority' => $priorityEnum,
                'dependencies' => null,
            ]);

            $created++;
        }

        Log::info("GenerateModuleSubmodulesJob: {$created} submódulos criados para '{$this->module->name}'");
    }
}
