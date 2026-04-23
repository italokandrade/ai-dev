<?php

namespace App\Jobs;

use App\Ai\Agents\ProjectPrdAgent;
use App\Models\Project;
use App\Services\AiRuntimeConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectPrdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public Project $project,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        Log::info("GenerateProjectPrdJob: Gerando PRD Master para '{$this->project->name}'");

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new ProjectPrdAgent($this->project->local_path ?? base_path()))
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $prdPayload = $response->data;

            Log::info("GenerateProjectPrdJob: PRD gerado com sucesso para '{$this->project->name}'", [
                'modules' => count($prdPayload['modules'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error("GenerateProjectPrdJob: Falha na geração do PRD", [
                'project' => $this->project->name,
                'error' => $e->getMessage(),
            ]);

            $prdPayload = $this->fallbackPrd($prompt, $e->getMessage());
        }

        $this->project->update([
            'prd_payload' => $prdPayload,
        ]);

        Log::info("GenerateProjectPrdJob: Concluído para '{$this->project->name}'");
    }

    private function buildPrompt(): string
    {
        $projectName = $this->project->name;
        $description = $this->project->description ?? 'Nenhuma descrição fornecida.';

        $backendFeatures = $this->project->backendFeatures
            ->map(fn ($f) => "- {$f->title}: {$f->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade backend cadastrada.';

        $frontendFeatures = $this->project->frontendFeatures
            ->map(fn ($f) => "- {$f->title}: {$f->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade frontend cadastrada.';

        return <<<PROMPT
PROJETO: {$projectName}

DESCRIÇÃO DO SISTEMA:
{$description}

FUNCIONALIDADES BACKEND JÁ CADASTRADAS:
{$backendFeatures}

FUNCIONALIDADES FRONTEND JÁ CADASTRADAS:
{$frontendFeatures}

---
INSTRUÇÃO: Com base em TODAS as informações acima, gere o PRD Master deste projeto.
O PRD deve respeitar EXATAMENTE as funcionalidades já cadastradas.
Não invente módulos ou funcionalidades que não foram solicitadas.
Divida tudo em módulos e submódulos pequenos, atômicos e de responsabilidade única.
PROMPT;
    }

    private function fallbackPrd(string $prompt, string $error): array
    {
        return [
            'title' => "{$this->project->name} — PRD Master",
            'objective' => 'Geração automática do PRD falhou. Por favor, revise os logs e tente novamente.',
            'scope_summary' => 'PRD não gerado devido a erro na chamada da IA.',
            'target_audience' => 'A ser definido',
            'modules' => [],
            'non_functional_requirements' => [],
            'estimated_complexity' => 'moderate',
            '_status' => 'ai_generation_failed',
            '_error' => $error,
            '_prompt' => $prompt,
        ];
    }
}
