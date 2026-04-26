<?php

namespace App\Jobs;

use App\Ai\Agents\ProjectBlueprintAgent;
use App\Models\Project;
use App\Services\AiRuntimeConfigService;
use App\Services\ProjectBlueprintService;
use App\Services\ProjectPlanningScopeService;
use App\Support\AiJson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectBlueprintJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(ProjectBlueprintService $blueprintService): void
    {
        $this->project->refresh();
        $this->project->markBlueprintGenerationStarted();
        $this->project->refresh();

        Log::info("GenerateProjectBlueprintJob: Gerando Blueprint Técnico para '{$this->project->name}'");

        if (! $this->project->isPrdReady()) {
            $this->project->update([
                'blueprint_payload' => $this->fallbackBlueprint('PRD Master ausente ou inválido.'),
            ]);
            SyncProjectRepositoryJob::dispatch($this->project->fresh());

            return;
        }

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = $this->promptWithRetries(fn () => (new ProjectBlueprintAgent)
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                ));

            $blueprint = $blueprintService->normalize($this->project, $this->parseBlueprint((string) $response));

            Log::info("GenerateProjectBlueprintJob: Blueprint gerado com sucesso para '{$this->project->name}'", [
                'entities' => count($blueprint['domain_model']['entities'] ?? []),
                'workflows' => count($blueprint['workflows'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateProjectBlueprintJob: Falha na geração do Blueprint', [
                'project' => $this->project->name,
                'error' => $e->getMessage(),
            ]);

            $blueprint = $this->fallbackBlueprint($e->getMessage());
        }

        $this->project->update([
            'blueprint_payload' => $blueprint,
            'blueprint_approved_at' => null,
        ]);

        SyncProjectRepositoryJob::dispatch($this->project->fresh());
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function promptWithRetries(callable $callback): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return (string) $callback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($attempt >= 3 || ! $this->isTransientAiFailure($e)) {
                    throw $e;
                }

                Log::warning('GenerateProjectBlueprintJob: falha transitória da IA, tentando novamente', [
                    'project' => $this->project->name,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                sleep($attempt * 2);
            }
        }

        throw $lastException ?? new \RuntimeException('Falha desconhecida ao chamar IA.');
    }

    private function isTransientAiFailure(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'connection reset')
            || str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'service unavailable')
            || str_contains($message, 'curl error');
    }

    private function buildPrompt(): string
    {
        $projectName = $this->project->name;
        $description = $this->project->description ?? 'Nenhuma descrição fornecida.';
        if (strlen($description) > 1500) {
            $description = substr($description, 0, 1500)."\n\n[...descrição truncada para otimização...]";
        }

        $backendFeatures = $this->project->backendFeatures
            ->map(fn ($feature) => "- {$feature->title}: {$feature->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade backend cadastrada.';

        $frontendFeatures = $this->project->frontendFeatures
            ->map(fn ($feature) => "- {$feature->title}: {$feature->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade frontend cadastrada.';

        $prd = json_encode($this->project->prd_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (strlen((string) $prd) > 12000) {
            $prd = substr((string) $prd, 0, 12000)."\n\n[...PRD truncado para otimização...]";
        }
        $scopeGuidance = app(ProjectPlanningScopeService::class)->promptGuidance($this->project->fresh(['features']), 'geracao do Blueprint Tecnico Global');

        return <<<PROMPT
PROJETO: {$projectName}

DESCRIÇÃO DO SISTEMA:
{$description}

FUNCIONALIDADES BACKEND:
{$backendFeatures}

FUNCIONALIDADES FRONTEND:
{$frontendFeatures}

PRD MASTER APROVADO:
{$prd}

{$scopeGuidance}

---
INSTRUÇÃO: Gere o Blueprint Técnico Global deste projeto.
Ele deve vir depois do PRD Master e antes dos módulos.
Ele deve conter MER/ERD conceitual sem campos, casos de uso, workflows, arquitetura C4 simplificada, contratos de API em alto nível e decisões não funcionais.
Inclua também cobertura por módulo, lifecycle de dados/conteúdo, estados relevantes, riscos e perguntas abertas.
O objetivo é dar profundidade para os PRDs dos módulos, sem criar migrations, campos finais, endpoints finais ou código.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBlueprint(string $raw): array
    {
        try {
            return AiJson::object($raw, 'Blueprint Tecnico Global');
        } catch (\Throwable $e) {
            Log::warning('GenerateProjectBlueprintJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 500),
                'json_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackBlueprint(string $error): array
    {
        return [
            'title' => "{$this->project->name} — Blueprint Técnico",
            'artifact_type' => 'technical_blueprint',
            'source' => 'fallback',
            'summary' => 'Geração automática do Blueprint falhou. Revise os logs e tente novamente.',
            'domain_model' => [
                'entities' => [],
                'relationships' => [],
            ],
            'use_cases' => [],
            'workflows' => [],
            'architecture' => [
                'containers' => [],
                'components' => [],
                'integrations' => [],
            ],
            'api_surface' => [],
            'non_functional_decisions' => [],
            'open_questions' => [],
            '_status' => 'ai_generation_failed',
            '_error' => $error,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateProjectBlueprintJob: Job falhou por completo', [
            'project' => $this->project->name,
            'error' => $exception->getMessage(),
        ]);

        $this->project->update([
            'blueprint_payload' => $this->fallbackBlueprint($exception->getMessage()),
            'blueprint_approved_at' => null,
        ]);
        SyncProjectRepositoryJob::dispatch($this->project->fresh());
    }
}
