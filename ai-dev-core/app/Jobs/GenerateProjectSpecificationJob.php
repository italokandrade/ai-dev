<?php

namespace App\Jobs;

use App\Ai\Agents\SpecificationAgent;
use App\Models\Project;
use App\Models\ProjectSpecification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiRuntimeConfigService;
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
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = SpecificationAgent::make()->prompt(
                $prompt,
                provider: $aiConfig['provider'],
                model: $aiConfig['model'],
            );
            $aiSpec   = $response->data;

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

        Log::info("GenerateProjectSpecificationJob: Concluído para '{$project->name}' — " . ($aiSpec['estimated_modules'] ?? 0) . " módulos estimados");
    }

    private function buildPrompt(string $userDescription): string
    {
        return <<<PROMPT
O usuário descreveu o sistema abaixo. Transforme em especificação técnica estruturada em JSON.

DESCRIÇÃO DO USUÁRIO:
{$userDescription}

DIRETRIZ DE ARQUITETURA E GRANULARIDADE (MUITO IMPORTANTE):
- Divida o sistema em módulos e submódulos muito bem definidos, atômicos e granulares.
- Os agentes de inteligência artificial terão mais facilidade de programar se cada módulo/submódulo tiver uma responsabilidade única e escopo isolado.
- Evite módulos monolíticos. Quebre funcionalidades grandes em submódulos específicos.
- A prioridade deve ser "high", "medium" ou "normal".

Retorne EXATAMENTE este JSON (sem markdown):
{
  "system_name": "Nome do Sistema",
  "target_audience": "Quem usa o sistema",
  "core_features": ["Feature 1", "Feature 2"],
  "technical_stack": {
    "backend": "Laravel 13 + PHP 8.3",
    "frontend": "Livewire 4 + Alpine.js v3 + Tailwind CSS v4",
    "admin": "Filament v5",
    "database": "PostgreSQL 16",
    "extras": []
  },

  "modules": [
    {
      "name": "Nome do Módulo Pai",
      "description": "Visão geral do módulo",
      "priority": "high",
      "dependencies": [],
      "submodules": [
        {
          "name": "Submódulo Específico",
          "description": "Responsabilidade única deste submódulo",
          "priority": "high",
          "dependencies": []
        }
      ]
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
            'target_audience'           => 'A ser definido',
            'core_features'             => ['Geração pela IA falhou — verifique os logs'],
            'technical_stack'           => [
                'backend'  => 'Laravel 13 + PHP 8.3',
                'frontend' => 'Livewire 4 + Alpine.js v3 + Tailwind CSS v4',
                'admin'    => 'Filament v5',
                'database' => 'PostgreSQL 16',
                'extras'   => [],
            ],

            'modules'                   => [],
            'estimated_modules'         => 0,
            'estimated_complexity'      => 'moderate',
            '_status'                   => 'ai_generation_failed',
            '_error'                    => $error,
            '_prompt'                   => $prompt,
        ];
    }
}
