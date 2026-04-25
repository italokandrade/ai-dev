<?php

namespace App\Jobs;

use App\Ai\Agents\ProjectPrdAgent;
use App\Models\Project;
use App\Services\AiRuntimeConfigService;
use App\Services\StandardProjectModuleService;
use App\Support\AiJson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectPrdJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->project->id;
    }

    public function __construct(
        public Project $project,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $this->project->refresh();
        $this->project->markPrdGenerationStarted();
        $this->project->refresh();

        Log::info("GenerateProjectPrdJob: Gerando PRD Master para '{$this->project->name}'");

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new ProjectPrdAgent)
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $prdPayload = $this->parsePrd((string) $response);

            Log::info("GenerateProjectPrdJob: PRD gerado com sucesso para '{$this->project->name}'", [
                'modules' => count($prdPayload['modules'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateProjectPrdJob: Falha na geração do PRD', [
                'project' => $this->project->name,
                'error' => $e->getMessage(),
            ]);

            $prdPayload = $this->fallbackPrd($prompt, $e->getMessage());
        }

        $this->project->update([
            'prd_payload' => app(StandardProjectModuleService::class)->mergeIntoProjectPrd($prdPayload),
        ]);

        SyncProjectRepositoryJob::dispatch($this->project->fresh());

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

        $standardModules = app(StandardProjectModuleService::class)->promptSummary();

        return <<<PROMPT
PROJETO: {$projectName}

DESCRIÇÃO DO SISTEMA:
{$description}

FUNCIONALIDADES BACKEND:
{$backendFeatures}

FUNCIONALIDADES FRONTEND:
{$frontendFeatures}

{$standardModules}

---
INSTRUÇÃO: Com base nas informações acima, gere o PRD Master deste projeto.
O PRD deve respeitar EXATAMENTE as funcionalidades já cadastradas.
Não invente módulos ou funcionalidades que não foram solicitadas.
Não inclua os módulos padrão Chatbox e Segurança em "modules"; eles serão anexados automaticamente em "standard_modules".
Divida tudo apenas em módulos de alto nível, sem submódulos, sem campos de banco e sem endpoints finais.
Depois do PRD, outro agente irá gerar o Blueprint Técnico Global com MER/ERD conceitual, casos de uso, workflows, arquitetura e APIs de alto nível.
PROMPT;
    }

    private function parsePrd(string $raw): array
    {
        try {
            return AiJson::object($raw, 'PRD Master');
        } catch (\Throwable $e) {
            Log::warning('GenerateProjectPrdJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 500),
                'json_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateProjectPrdJob: Job falhou por completo', [
            'project' => $this->project->name,
            'error' => $exception->getMessage(),
        ]);

        $this->project->update([
            'prd_payload' => $this->fallbackPrd('', $exception->getMessage()),
        ]);
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
