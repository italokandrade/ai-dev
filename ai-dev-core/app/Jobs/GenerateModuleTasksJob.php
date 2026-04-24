<?php

namespace App\Jobs;

use App\Enums\Priority;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\ProjectModule;
use App\Models\Task;
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
        Log::info("GenerateModuleTasksJob: Criando tasks para '{$this->module->name}'");

        $prd = $this->module->prd_payload;

        if (empty($prd)) {
            Log::info('GenerateModuleTasksJob: Nenhum PRD encontrado.');

            return;
        }

        $tasks = [];

        // 1. Componentes → Tasks
        foreach ($prd['components'] ?? [] as $component) {
            $tasks[] = [
                'title' => "Implementar {$component['type']}: {$component['name']}",
                'description' => $component['description'] ?? 'Sem descrição',
                'priority' => Priority::High,
            ];
        }

        // 2. Workflows → Tasks
        foreach ($prd['workflows'] ?? [] as $workflow) {
            $steps = collect($workflow['steps'] ?? [])->map(fn ($s) => is_array($s) ? ($s['name'] ?? json_encode($s)) : $s)->implode(' → ');
            $tasks[] = [
                'title' => "Fluxo: {$workflow['name']}",
                'description' => 'Steps: '.$steps,
                'priority' => Priority::High,
            ];
        }

        // 3. APIs → Tasks
        foreach ($prd['api_endpoints'] ?? [] as $api) {
            $tasks[] = [
                'title' => "API {$api['method']} {$api['uri']}",
                'description' => $api['description'] ?? 'Sem descrição',
                'priority' => Priority::Medium,
            ];
        }

        // 4. Database schema → Tasks (uma por tabela)
        foreach ($prd['database_schema']['tables'] ?? [] as $table) {
            $tasks[] = [
                'title' => "Migration: {$table['name']}",
                'description' => $table['description'] ?? 'Criar tabela no banco de dados',
                'priority' => Priority::High,
            ];
        }

        // 5. Critérios de aceitação → Tasks de teste
        foreach ($prd['acceptance_criteria'] ?? [] as $criteria) {
            $tasks[] = [
                'title' => "Teste: {$criteria}",
                'description' => "Garantir que o critério de aceitação seja atendido: {$criteria}",
                'priority' => Priority::Medium,
            ];
        }

        $created = 0;

        foreach ($tasks as $taskData) {
            Task::create([
                'project_id' => $this->module->project_id,
                'module_id' => $this->module->id,
                'title' => $taskData['title'],
                'prd_payload' => [
                    'objective' => $taskData['description'],
                    'acceptance_criteria' => $prd['acceptance_criteria'] ?? [],
                    'constraints' => [
                        'Usar a stack TALL + Filament v5 definida pelo projeto alvo.',
                        'Consultar Boost do projeto alvo antes de implementar.',
                    ],
                    'knowledge_areas' => ['laravel', 'filament', 'livewire', 'tailwind'],
                    'module_context' => [
                        'module_id' => $this->module->id,
                        'module_name' => $this->module->name,
                        'module_prd_title' => $prd['title'] ?? null,
                    ],
                    'blueprint_context' => [
                        'module_blueprint' => $this->module->blueprint_payload,
                    ],
                ],
                'status' => TaskStatus::Pending,
                'priority' => $taskData['priority'],
                'source' => TaskSource::Prd,
                'max_retries' => 3,
            ]);
            $created++;
        }

        Log::info("GenerateModuleTasksJob: {$created} tasks criadas para '{$this->module->name}'");
    }
}
