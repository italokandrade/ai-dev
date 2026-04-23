<?php

namespace App\Jobs;

use App\Ai\Agents\GenerateFeaturesAgent;
use App\Models\Project;
use App\Models\ProjectFeature;
use App\Services\AiRuntimeConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectFeaturesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        public Project $project,
        public string $type, // 'backend' ou 'frontend'
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        Log::info("GenerateProjectFeaturesJob: Gerando funcionalidades {$this->type} para '{$this->project->name}'");

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new GenerateFeaturesAgent())
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $raw = (string) $response;
            $features = $this->parseFeatures($raw);

            Log::info("GenerateProjectFeaturesJob: IA gerou " . count($features) . " funcionalidades {$this->type}");
        } catch (\Throwable $e) {
            Log::error("GenerateProjectFeaturesJob: Falha na geração de funcionalidades", [
                'project' => $this->project->name,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $created = 0;
        foreach ($features as $featureData) {
            try {
                ProjectFeature::create([
                    'project_id' => $this->project->id,
                    'type' => $this->type,
                    'title' => $featureData['title'] ?? 'Funcionalidade sem título',
                    'description' => $featureData['description'] ?? '',
                ]);
                $created++;
            } catch (\Exception $e) {
                Log::warning("GenerateProjectFeaturesJob: Falha ao inserir funcionalidade", [
                    'title' => $featureData['title'] ?? '—',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("GenerateProjectFeaturesJob: {$created} funcionalidades {$this->type} inseridas para '{$this->project->name}'");
    }

    private function parseFeatures(string $raw): array
    {
        // Remove markdown fences se existirem
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $clean = trim($clean);

        $data = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GenerateProjectFeaturesJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            return [];
        }

        return $data['features'] ?? [];
    }

    private function buildPrompt(): string
    {
        $projectName = $this->project->name;
        $description = $this->project->description ?? 'Nenhuma descrição fornecida.';

        // Trunca descrição muito longa para evitar timeout na IA
        if (strlen($description) > 3000) {
            $description = substr($description, 0, 3000) . "\n\n[...descrição truncada para otimização...]";
        }
        $typeLabel = $this->type === 'backend' ? 'BACKEND' : 'FRONTEND';
        $typeInstruction = $this->type === 'backend'
            ? 'Gere funcionalidades de BACKEND: regras de negócio, APIs, processamentos, jobs, validações, integrações, notificações, relatórios, segurança, auditoria, caches, filas.'
            : 'Gere funcionalidades de FRONTEND: telas, componentes visuais, fluxos do usuário, interações, formulários, dashboards, listagens, filtros, animações, responsividade, acessibilidade.';

        $prdContext = '';
        if (!empty($this->project->prd_payload)) {
            $prd = $this->project->prd_payload;
            $prdContext = "\n\nPRD DO PROJETO:\n";
            $prdContext .= "Título: " . ($prd['title'] ?? '—') . "\n";
            $prdContext .= "Objetivo: " . ($prd['objective'] ?? '—') . "\n";
            if (!empty($prd['modules'])) {
                $prdContext .= "Módulos:\n";
                foreach ($prd['modules'] as $mod) {
                    $prdContext .= "- " . ($mod['name'] ?? '—') . ": " . ($mod['description'] ?? '—') . "\n";
                    foreach ($mod['submodules'] ?? [] as $sub) {
                        $prdContext .= "  • " . ($sub['name'] ?? '—') . ": " . ($sub['description'] ?? '—') . "\n";
                    }
                }
            }
        }

        return <<<PROMPT
PROJETO: {$projectName}

DESCRIÇÃO DO SISTEMA:
{$description}
{$prdContext}

---
INSTRUÇÃO: Gere funcionalidades de {$typeLabel} para este projeto.
{$typeInstruction}

IMPORTANTE:
- Cada funcionalidade deve ter título curto e descrição clara.
- Descreva O QUE a funcionalidade faz, não COMO fazer.
- NÃO cite frameworks, versões ou tecnologias específicas.
- Texto em Português do Brasil.
PROMPT;
    }
}
