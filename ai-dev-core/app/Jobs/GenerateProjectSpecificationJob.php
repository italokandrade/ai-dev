<?php

namespace App\Jobs;

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
    public int $timeout = 120;

    public function __construct(
        public ProjectSpecification $specification,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $userDescription = $this->specification->user_description;
        $project = $this->specification->project;

        Log::info("GenerateProjectSpecificationJob: Gerando especificação para '{$project->name}'");

        $prompt = $this->buildPrompt($userDescription);

        // TODO: Quando o Laravel AI SDK estiver configurado, substituir por:
        // $result = SpecificationAgent::make()->prompt($prompt, provider: Lab::Anthropic, model: 'claude-sonnet-4-6');
        //
        // Por enquanto, salva a estrutura base para o usuário completar manualmente
        // ou para ser chamado via API do Claude diretamente.

        $aiSpec = $this->generatePlaceholderSpec($userDescription, $project->name);

        $this->specification->update([
            'ai_specification' => $aiSpec,
        ]);

        // Criar módulos sugeridos
        $this->createModulesFromSpec($project, $aiSpec);

        Log::info("GenerateProjectSpecificationJob: Especificação gerada para '{$project->name}' com {$aiSpec['estimated_modules']} módulos");
    }

    private function buildPrompt(string $userDescription): string
    {
        return <<<PROMPT
Você é um arquiteto de software especializado em Laravel 13 + TALL Stack (Tailwind, Alpine.js, Livewire, Filament v5).

O usuário descreveu o sistema que deseja construir. Sua tarefa é:

1. Reescrever a descrição informal em uma ESPECIFICAÇÃO TÉCNICA ESTRUTURADA
2. Decompor o sistema em MÓDULOS independentes com critérios de aceite

DESCRIÇÃO DO USUÁRIO:
{$userDescription}

RETORNE um JSON com EXATAMENTE esta estrutura:
{
  "system_name": "Nome do Sistema",
  "objective": "Descrição técnica detalhada do que o sistema faz",
  "target_audience": "Quem usa o sistema",
  "core_features": ["Feature 1", "Feature 2", ...],
  "technical_stack": {
    "backend": "Laravel 13 + PHP 8.3",
    "frontend": "Livewire 4 + Alpine.js v3 + Tailwind CSS v4",
    "admin": "Filament v5",
    "animations": "Anime.js",
    "database": "PostgreSQL 16",
    "extras": ["pacote1", "pacote2"]
  },
  "non_functional_requirements": ["NFR 1", "NFR 2", ...],
  "modules": [
    {
      "name": "Nome do Módulo",
      "description": "O que este módulo faz",
      "acceptance_criteria": ["Critério 1", "Critério 2", ...],
      "dependencies": [],
      "estimated_tasks": 3,
      "priority": 90
    }
  ],
  "estimated_modules": 6,
  "estimated_complexity": "moderate"
}

REGRAS:
- Módulos devem ser granulares e independentes quando possível
- Cada módulo deve ter pelo menos 3 critérios de aceite mensuráveis
- A ordem dos módulos deve respeitar dependências (ex: Auth antes de CRUD admin)
- Stack é SEMPRE Laravel 13 + TALL + Filament v5 + PostgreSQL 16
- Retorne APENAS o JSON, sem markdown ou explicações
PROMPT;
    }

    /**
     * Gera uma especificação placeholder baseada na descrição do usuário.
     * Será substituído pela chamada real ao LLM quando o SDK estiver configurado.
     */
    private function generatePlaceholderSpec(string $userDescription, string $projectName): array
    {
        return [
            'system_name' => $projectName,
            'objective' => $userDescription,
            'target_audience' => 'A ser definido pela IA',
            'core_features' => ['Aguardando geração pela IA'],
            'technical_stack' => [
                'backend' => 'Laravel 13 + PHP 8.3',
                'frontend' => 'Livewire 4 + Alpine.js v3 + Tailwind CSS v4',
                'admin' => 'Filament v5',
                'animations' => 'Anime.js',
                'database' => 'PostgreSQL 16',
                'extras' => [],
            ],
            'non_functional_requirements' => ['Aguardando geração pela IA'],
            'modules' => [],
            'estimated_modules' => 0,
            'estimated_complexity' => 'moderate',
            '_status' => 'pending_ai_generation',
            '_prompt' => $this->buildPrompt($userDescription),
        ];
    }

    private function createModulesFromSpec(Project $project, array $aiSpec): void
    {
        if (empty($aiSpec['modules'])) {
            return;
        }

        $moduleIdMap = [];
        $order = 1;

        foreach ($aiSpec['modules'] as $moduleData) {
            $module = ProjectModule::create([
                'project_id' => $project->id,
                'name' => $moduleData['name'],
                'description' => $moduleData['description'],
                'status' => ModuleStatus::Planned,
                'priority' => $moduleData['priority'] ?? 50,
                'order' => $order++,
                'dependencies' => null, // Resolvido após todos serem criados
                'acceptance_criteria' => $moduleData['acceptance_criteria'] ?? [],
                'estimated_tasks' => $moduleData['estimated_tasks'] ?? null,
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
