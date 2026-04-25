<?php

namespace App\Jobs;

use App\Ai\Agents\GenerateFeaturesAgent;
use App\Models\Project;
use App\Models\ProjectFeature;
use App\Services\AiRuntimeConfigService;
use App\Services\ProjectPlanningScopeService;
use App\Support\AiJson;
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
        if (! in_array($this->type, ['backend', 'frontend'], true)) {
            Log::warning('GenerateProjectFeaturesJob: tipo de funcionalidade inválido', [
                'project' => $this->project->name,
                'type' => $this->type,
            ]);

            return;
        }

        Log::info("GenerateProjectFeaturesJob: Gerando funcionalidades {$this->type} para '{$this->project->name}'");

        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new GenerateFeaturesAgent)
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $raw = (string) $response;
            $features = app(ProjectPlanningScopeService::class)
                ->sanitizeFeatures($this->project->fresh(['features']), $this->type, $this->parseFeatures($raw));

            Log::info('GenerateProjectFeaturesJob: IA gerou '.count($features)." funcionalidades {$this->type}");
        } catch (\Throwable $e) {
            Log::error('GenerateProjectFeaturesJob: Falha na geração de funcionalidades', [
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
                Log::warning('GenerateProjectFeaturesJob: Falha ao inserir funcionalidade', [
                    'title' => $featureData['title'] ?? '—',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("GenerateProjectFeaturesJob: {$created} funcionalidades {$this->type} inseridas para '{$this->project->name}'");
    }

    private function parseFeatures(string $raw): array
    {
        try {
            $data = AiJson::value($raw, 'funcionalidades do projeto');
        } catch (\Throwable $e) {
            Log::warning('GenerateProjectFeaturesJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 500),
                'json_error' => $e->getMessage(),
            ]);

            return [];
        }

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        return is_array($data) ? ($data['features'] ?? []) : [];
    }

    private function buildPrompt(): string
    {
        $projectName = $this->project->name;
        $description = $this->project->description ?? 'Nenhuma descrição fornecida.';

        // Trunca descrição muito longa para evitar timeout na IA
        if (strlen($description) > 3000) {
            $description = substr($description, 0, 3000)."\n\n[...descrição truncada para otimização...]";
        }
        $typeLabel = $this->type === 'backend' ? 'BACKEND' : 'FRONTEND';
        $typeInstruction = $this->type === 'backend'
            ? 'Gere somente funcionalidades de BACKEND que sejam indispensáveis para suportar o escopo descrito. Se o projeto for uma landing page/site estático, retorne poucas funcionalidades ou nenhuma. Não crie CRM, motor de cotação, agentes, webhooks, importações, dashboards ou gestão interna a menos que estejam explicitamente pedidos como produto operacional.'
            : 'Gere somente funcionalidades de FRONTEND visíveis ao usuário final ou administrador solicitado. Para landing page/site público, foque nas seções, navegação, formulário, responsividade, acessibilidade e estados visuais. Não crie dashboards, CRUDs ou rotinas administrativas se isso não estiver explicitamente pedido.';
        $scopeGuidance = app(ProjectPlanningScopeService::class)->promptGuidance($this->project->fresh(['features']), "geracao de funcionalidades {$this->type}");

        $prdContext = '';
        if (! empty($this->project->prd_payload)) {
            $prd = $this->project->prd_payload;
            $prdContext = "\n\nPRD DO PROJETO:\n";
            $prdContext .= 'Título: '.($prd['title'] ?? '—')."\n";
            $prdContext .= 'Objetivo: '.($prd['objective'] ?? '—')."\n";
            if (! empty($prd['modules'])) {
                $prdContext .= "Módulos:\n";
                foreach ($prd['modules'] as $mod) {
                    $prdContext .= '- '.($mod['name'] ?? '—').': '.($mod['description'] ?? '—')."\n";
                    foreach ($mod['submodules'] ?? [] as $sub) {
                        $prdContext .= '  • '.($sub['name'] ?? '—').': '.($sub['description'] ?? '—')."\n";
                    }
                }
            }
        }

        return <<<PROMPT
PROJETO: {$projectName}

DESCRIÇÃO DO SISTEMA:
{$description}
{$prdContext}

{$scopeGuidance}

---
INSTRUÇÃO: Gere funcionalidades de {$typeLabel} para este projeto.
{$typeInstruction}

IMPORTANTE:
- Cada funcionalidade deve ter título curto e descrição clara.
- Descreva O QUE a funcionalidade faz, não COMO fazer.
- NÃO cite frameworks, versões ou tecnologias específicas.
- Não liste ideias, oportunidades futuras ou módulos internos não solicitados.
- Não gere mais itens do que o necessário para cumprir a descrição.
- Texto em Português do Brasil.
PROMPT;
    }
}
