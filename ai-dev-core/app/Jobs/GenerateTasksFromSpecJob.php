<?php

namespace App\Jobs;

use App\Enums\Priority;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;
use App\Models\Task;
use App\Models\TaskTransition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateTasksFromSpecJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public ProjectSpecification $specification,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $spec = $this->specification;
        $project = $spec->project;

        Log::info("GenerateTasksFromSpecJob: Generating tasks for project '{$project->name}'");

        $submodules = ProjectModule::where('project_id', $project->id)
            ->whereNotNull('parent_id')
            ->with('parent')
            ->orderBy('created_at')
            ->get();

        if ($submodules->isEmpty()) {
            Log::warning("GenerateTasksFromSpecJob: No submodules found for '{$project->name}', dispatching quotation anyway");
            GenerateProjectQuotationJob::dispatch($project);

            return;
        }

        $aiSpec = $spec->ai_specification ?? [];
        $stackNote = 'Stack: Laravel 13 + PHP 8.3 + Filament v5 + Livewire 4 + Tailwind CSS v4 + PostgreSQL 16';
        $tasksCreated = 0;

        foreach ($submodules as $submodule) {
            $prd = [
                'objective' => $submodule->description ?: "Implementar o submódulo '{$submodule->name}'.",
                'acceptance_criteria' => $this->buildAcceptanceCriteria($submodule, $aiSpec),
                'constraints' => [
                    $stackNote,
                    'Seguir PSR-12 e convenções Laravel',
                    'Models devem usar HasUuids + uuid primary key',
                ],
                'knowledge_areas' => $this->inferKnowledgeAreas($submodule->name),
                'spec_version' => $spec->version,
                'module_name' => $submodule->parent?->name ?? $submodule->name,
                'submodule_name' => $submodule->name,
            ];

            $task = Task::create([
                'project_id' => $project->id,
                'module_id' => $submodule->id,
                'title' => $submodule->name,
                'prd_payload' => $prd,
                'status' => TaskStatus::Pending,
                'priority' => $submodule->priority ?? Priority::Normal,
                'source' => TaskSource::Specification,
                'max_retries' => 3,
            ]);

            TaskTransition::create([
                'entity_type' => 'task',
                'entity_id' => $task->id,
                'from_status' => null,
                'to_status' => TaskStatus::Pending->value,
                'triggered_by' => 'specification_approval',
                'metadata' => ['spec_id' => $spec->id, 'submodule_id' => $submodule->id],
            ]);

            $tasksCreated++;
        }

        Log::info("GenerateTasksFromSpecJob: Created {$tasksCreated} tasks for '{$project->name}'");

        GenerateProjectQuotationJob::dispatch($project);
    }

    /** @return string[] */
    private function buildAcceptanceCriteria(ProjectModule $submodule, array $aiSpec): array
    {
        $criteria = [
            "Submódulo '{$submodule->name}' completamente implementado",
            'Todos os testes unitários e de feature passam',
            'Código segue as convenções Laravel 13 e PHP 8.3',
        ];

        $nameLower = strtolower($submodule->name);

        if (str_contains($nameLower, 'migr') || str_contains($nameLower, 'tabela') || str_contains($nameLower, 'banco')) {
            $criteria[] = 'Migration criada e executada com sucesso';
            $criteria[] = 'Model com HasUuids, casts e relacionamentos corretos';
        }

        if (str_contains($nameLower, 'filament') || str_contains($nameLower, 'admin') || str_contains($nameLower, 'resource')) {
            $criteria[] = 'Resource Filament v5 com form, table e infolist completos';
        }

        if (str_contains($nameLower, 'api') || str_contains($nameLower, 'endpoint')) {
            $criteria[] = 'Endpoints retornam Eloquent API Resources';
            $criteria[] = 'Validação via Form Request';
        }

        if (str_contains($nameLower, 'auth') || str_contains($nameLower, 'login') || str_contains($nameLower, 'acesso')) {
            $criteria[] = 'Autenticação e autorização implementadas';
            $criteria[] = 'Policies configuradas';
        }

        return $criteria;
    }

    /** @return string[] */
    private function inferKnowledgeAreas(string $moduleName): array
    {
        $name = strtolower($moduleName);
        $areas = ['PHP 8.3', 'Laravel 13'];

        if (str_contains($name, 'front') || str_contains($name, 'view') || str_contains($name, 'layout') || str_contains($name, 'ui')) {
            $areas[] = 'Livewire 4';
            $areas[] = 'Alpine.js v3';
            $areas[] = 'Tailwind CSS v4';
        } elseif (str_contains($name, 'admin') || str_contains($name, 'filament') || str_contains($name, 'resource')) {
            $areas[] = 'Filament v5';
        }

        if (str_contains($name, 'queue') || str_contains($name, 'job') || str_contains($name, 'fila')) {
            $areas[] = 'Laravel Horizon';
            $areas[] = 'Redis';
        }

        if (str_contains($name, 'auth') || str_contains($name, 'login') || str_contains($name, 'user') || str_contains($name, 'permiss')) {
            $areas[] = 'Laravel Sanctum / Policies';
        }

        if (str_contains($name, 'api') || str_contains($name, 'webhook')) {
            $areas[] = 'REST API / Eloquent Resources';
        }

        if (str_contains($name, 'email') || str_contains($name, 'notif') || str_contains($name, 'mail')) {
            $areas[] = 'Laravel Mail / Notifications';
        }

        if (str_contains($name, 'banco') || str_contains($name, 'migr') || str_contains($name, 'model')) {
            $areas[] = 'Eloquent ORM';
            $areas[] = 'PostgreSQL 16';
        }

        return array_unique($areas);
    }
}
