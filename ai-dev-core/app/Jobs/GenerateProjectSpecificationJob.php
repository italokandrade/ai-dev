<?php

namespace App\Jobs;

use App\Ai\Agents\SpecificationAgent;
use App\Enums\ModuleStatus;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectSpecificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public ProjectSpecification $specification,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $userDescription = $this->specification->user_description;
        $project         = $this->specification->project;

        Log::info("GenerateProjectSpecificationJob: Gerando especificação para '{$project->name}'");

        $prompt = $this->buildPrompt($userDescription);

        try {
            $response = SpecificationAgent::make()->prompt($prompt);
            $raw      = trim((string) $response);

            // Remove possíveis blocos markdown ```json ... ```
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);

            $aiSpec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            Log::info("GenerateProjectSpecificationJob: IA respondeu com sucesso para '{$project->name}'", [
                'modules' => count($aiSpec['modules'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error("GenerateProjectSpecificationJob: Falha na geração pela IA", [
                'project' => $project->name,
                'error'   => $e->getMessage(),
            ]);

            // Fallback: salva o prompt para reprocessamento manual
            $aiSpec = $this->fallbackSpec($userDescription, $project->name, $prompt, $e->getMessage());
        }

        $this->specification->update([
            'ai_specification' => $aiSpec,
        ]);

        $this->createModulesFromSpec($project, $aiSpec);

        Log::info("GenerateProjectSpecificationJob: Concluído para '{$project->name}' — {$aiSpec['estimated_modules']} módulos");
    }

    private function buildPrompt(string $userDescription): string
    {
        return <<<PROMPT
O usuário descreveu o sistema abaixo. Transforme em especificação técnica estruturada.

DESCRIÇÃO DO USUÁRIO:
{$userDescription}

Retorne EXATAMENTE este JSON (sem markdown):
{
  "system_name": "Nome do Sistema",
  "objective": "Descrição técnica detalhada",
  "target_audience": "Quem usa o sistema",
  "core_features": ["Feature 1", "Feature 2"],
  "technical_stack": {
    "backend": "Laravel 13 + PHP 8.3",
    "frontend": "Livewire 4 + Alpine.js v3 + Tailwind CSS v4",
    "admin": "Filament v5",
    "database": "PostgreSQL 16",
    "extras": []
  },
  "non_functional_requirements": ["NFR 1", "NFR 2"],
  "modules": [
    {
      "name": "Nome do Módulo",
      "description": "O que este módulo faz",
      "acceptance_criteria": ["Critério 1", "Critério 2", "Critério 3"],
      "dependencies": [],
      "estimated_tasks": 4,
      "priority": 90
    }
  ],
  "estimated_modules": 6,
  "estimated_complexity": "moderate"
}
PROMPT;
    }

    private function fallbackSpec(string $userDescription, string $projectName, string $prompt, string $error): array
    {
        return [
            'system_name'               => $projectName,
            'objective'                 => $userDescription,
            'target_audience'           => 'A ser definido',
            'core_features'             => ['Geração pela IA falhou — verifique os logs'],
            'technical_stack'           => [
                'backend'  => 'Laravel 13 + PHP 8.3',
                'frontend' => 'Livewire 4 + Alpine.js v3 + Tailwind CSS v4',
                'admin'    => 'Filament v5',
                'database' => 'PostgreSQL 16',
                'extras'   => [],
            ],
            'non_functional_requirements' => [],
            'modules'                   => [],
            'estimated_modules'         => 0,
            'estimated_complexity'      => 'moderate',
            '_status'                   => 'ai_generation_failed',
            '_error'                    => $error,
            '_prompt'                   => $prompt,
        ];
    }

    private function createModulesFromSpec(Project $project, array $aiSpec): void
    {
        if (empty($aiSpec['modules'])) {
            return;
        }

        // Remove módulos anteriores desta versão (se reprocessamento)
        // Mantém apenas os criados por versões anteriores de spec
        $moduleIdMap = [];

        foreach ($aiSpec['modules'] as $moduleData) {
            $priorityVal = $moduleData['priority'] ?? 50;
            $priorityEnum = match (true) {
                $priorityVal >= 80 => \App\Enums\Priority::High,
                $priorityVal >= 40 => \App\Enums\Priority::Medium,
                default => \App\Enums\Priority::Normal,
            };

            $module = ProjectModule::create([
                'project_id'         => $project->id,
                'name'               => $moduleData['name'],
                'description'        => $moduleData['description'],
                'status'             => ModuleStatus::Planned,
                'priority'           => $priorityEnum,
                'dependencies'       => null,
            ]);

            $moduleIdMap[$moduleData['name']] = $module->id;
        }

        // Resolver dependências por nome → UUID
        foreach ($aiSpec['modules'] as $moduleData) {
            if (! empty($moduleData['dependencies'])) {
                $depIds = collect($moduleData['dependencies'])
                    ->map(fn ($depName) => $moduleIdMap[$depName] ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($depIds)) {
                    ProjectModule::where('project_id', $project->id)
                        ->where('name', $moduleData['name'])
                        ->update(['dependencies' => $depIds]);
                }
            }
        }
    }
}
