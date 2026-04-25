<?php

namespace App\Jobs;

use App\Ai\Agents\ModulePrdAgent;
use App\Models\ProjectModule;
use App\Services\AiRuntimeConfigService;
use App\Services\ProjectBlueprintService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateModulePrdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public ProjectModule $module,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(ProjectBlueprintService $blueprintService): void
    {
        Log::info("GenerateModulePrdJob: Gerando PRD para módulo '{$this->module->name}'");

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new ModulePrdAgent)
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $prdPayload = $this->parsePrd((string) $response);

            Log::info("GenerateModulePrdJob: PRD gerado com sucesso para '{$this->module->name}'");
        } catch (\Throwable $e) {
            Log::error('GenerateModulePrdJob: Falha na geração do PRD', [
                'module' => $this->module->name,
                'error' => $e->getMessage(),
            ]);

            $prdPayload = $this->fallbackPrd($e->getMessage());
        }

        $this->module->update([
            'prd_payload' => $prdPayload,
        ]);

        if (empty($prdPayload['_status'] ?? null)) {
            $blueprintService->mergeModulePrd($this->module->fresh(['project', 'parent']), $prdPayload);
        }

        SyncProjectRepositoryJob::dispatch($this->module->project->fresh());

        Log::info("GenerateModulePrdJob: Concluído para '{$this->module->name}'");
    }

    private function buildPrompt(): string
    {
        $project = $this->module->project;
        $moduleName = $this->module->name;
        $moduleDescription = $this->module->description ?? 'Nenhuma descrição fornecida.';
        $isSubmodule = $this->module->parent_id !== null;
        $parentName = $isSubmodule ? ($this->module->parent?->name ?? 'Módulo Pai') : null;

        $projectDescription = $project->description ?? '';
        if (strlen($projectDescription) > 1000) {
            $projectDescription = substr($projectDescription, 0, 1000)."\n\n[...descrição truncada...]";
        }

        // Buscar funcionalidades relacionadas ao módulo (por similaridade de nome)
        $backendFeatures = $project->backendFeatures
            ->filter(fn ($f) => str_contains(strtolower($f->title), strtolower($moduleName)) ||
                               str_contains(strtolower($moduleName), strtolower($f->title)))
            ->map(fn ($f) => "- {$f->title}: {$f->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade backend diretamente relacionada.';

        $frontendFeatures = $project->frontendFeatures
            ->filter(fn ($f) => str_contains(strtolower($f->title), strtolower($moduleName)) ||
                               str_contains(strtolower($moduleName), strtolower($f->title)))
            ->map(fn ($f) => "- {$f->title}: {$f->description}")
            ->implode("\n") ?: 'Nenhuma funcionalidade frontend diretamente relacionada.';

        $typeLabel = $isSubmodule ? 'SUBMÓDULO' : 'MÓDULO';
        $parentInfo = $isSubmodule ? "\nMÓDULO PAI: {$parentName}\n" : '';
        $blueprintContext = $this->blueprintContext();

        return <<<PROMPT
PROJETO: {$project->name}

DESCRIÇÃO DO PROJETO:
{$projectDescription}

{$typeLabel}: {$moduleName}
{$parentInfo}
DESCRIÇÃO DO {$typeLabel}:
{$moduleDescription}

FUNCIONALIDADES BACKEND RELACIONADAS:
{$backendFeatures}

FUNCIONALIDADES FRONTEND RELACIONADAS:
{$frontendFeatures}

BLUEPRINT TÉCNICO GLOBAL ATUAL:
{$blueprintContext}

---
INSTRUÇÃO: Gere o PRD Técnico DETALHADO deste {$typeLabel}.
Este PRD será usado por desenvolvedores para implementar o código.
Seja específico sobre: tabelas de banco, APIs, componentes, regras de negócio,
validações, permissões e fluxos de trabalho. Atualize também o `blueprint_contribution`
com entidades, campos, relacionamentos, casos de uso, workflows e componentes que este {$typeLabel} adiciona ou detalha.
PROMPT;
    }

    private function blueprintContext(): string
    {
        $blueprint = $this->module->project->blueprint_payload;

        if (empty($blueprint) || ! is_array($blueprint)) {
            return 'Nenhum Blueprint Técnico Global aprovado ou gerado. Reutilize apenas o PRD Master e não invente entidades fora do escopo do módulo.';
        }

        $json = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (strlen((string) $json) > 10000) {
            return substr((string) $json, 0, 10000)."\n\n[...Blueprint truncado para otimização...]";
        }

        return (string) $json;
    }

    private function parsePrd(string $raw): array
    {
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $clean = trim($clean);

        $data = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GenerateModulePrdJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('JSON inválido retornado pela IA: '.json_last_error_msg());
        }

        return $data;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateModulePrdJob: Job falhou por completo', [
            'module' => $this->module->name,
            'error' => $exception->getMessage(),
        ]);

        $this->module->update([
            'prd_payload' => $this->fallbackPrd($exception->getMessage()),
        ]);
        SyncProjectRepositoryJob::dispatch($this->module->project->fresh());
    }

    private function fallbackPrd(string $error): array
    {
        return [
            'title' => "{$this->module->name} — PRD Técnico",
            'objective' => 'Geração automática do PRD falhou. Por favor, revise os logs e tente novamente.',
            'scope' => 'PRD não gerado devido a erro na chamada da IA.',
            'database_schema' => ['tables' => []],
            'api_endpoints' => [],
            'business_rules' => [],
            'components' => [],
            'workflows' => [],
            'acceptance_criteria' => [],
            'non_functional_requirements' => [],
            'estimated_complexity' => 'moderate',
            'estimated_hours' => 0,
            '_status' => 'ai_generation_failed',
            '_error' => $error,
        ];
    }
}
