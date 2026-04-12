<?php

namespace App\Jobs;

use App\Ai\Agents\QuotationAgent;
use App\Models\Project;
use App\Models\ProjectQuotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProjectQuotationJob implements ShouldQueue
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
        Log::info("GenerateProjectQuotationJob: Generating quotation for '{$this->project->name}'");

        $spec = $this->project->currentSpecification;
        $aiSpec = $spec?->ai_specification ?? [];

        // Check if there's already a quotation that hasn't been approved
        $existing = ProjectQuotation::where('project_id', $this->project->id)
            ->whereIn('status', ['draft'])
            ->first();

        if ($existing) {
            Log::info("GenerateProjectQuotationJob: Draft quotation already exists for '{$this->project->name}', skipping");

            return;
        }

        $prompt = $this->buildPrompt($aiSpec);

        try {
            $response = QuotationAgent::make()->prompt($prompt);
            $raw = trim((string) $response);

            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $hours = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::error("GenerateProjectQuotationJob: QuotationAgent failed — {$e->getMessage()}. Using zero hours.");
            $hours = [];
        }

        $complexity = $this->inferComplexityLevel($aiSpec);
        $urgency = 1; // Normal por padrão — pode ser ajustado pelo usuário

        $quotation = ProjectQuotation::create([
            'project_id' => $this->project->id,
            'client_name' => 'A definir',
            'project_name' => $this->project->name,
            'project_description' => $aiSpec['objective'] ?? $spec?->user_description ?? '',
            'complexity_level' => $complexity,
            'urgency_level' => $urgency,
            'required_areas' => $this->inferRequiredAreas($hours),
            'backend_hours' => (int) ($hours['backend_hours'] ?? 0),
            'frontend_hours' => (int) ($hours['frontend_hours'] ?? 0),
            'mobile_hours' => (int) ($hours['mobile_hours'] ?? 0),
            'database_hours' => (int) ($hours['database_hours'] ?? 0),
            'devops_hours' => (int) ($hours['devops_hours'] ?? 0),
            'design_hours' => (int) ($hours['design_hours'] ?? 0),
            'testing_hours' => (int) ($hours['testing_hours'] ?? 0),
            'security_hours' => (int) ($hours['security_hours'] ?? 0),
            'pm_hours' => (int) ($hours['pm_hours'] ?? 0),
            'status' => 'draft',
            'notes' => isset($hours['justification'])
                ? "Estimativa gerada automaticamente pela IA.\n\nJustificativa: {$hours['justification']}"
                : 'Estimativa gerada automaticamente pela IA após aprovação da especificação.',
        ]);

        $quotation->recalculate();
        $quotation->save();

        Log::info("GenerateProjectQuotationJob: Quotation created for '{$this->project->name}' — ".
            "Total: {$quotation->total_human_hours}h | Custo: R$ {$quotation->total_human_cost} | AI-Dev: R$ {$quotation->ai_dev_price}");
    }

    private function buildPrompt(array $aiSpec): string
    {
        $name = $this->project->name;
        $objective = $aiSpec['objective'] ?? 'Não definido';
        $complexity = match ($this->inferComplexityLevel($aiSpec)) {
            1 => 'Simples',
            2 => 'Médio',
            3 => 'Complexo',
            4 => 'Enterprise',
            default => 'Médio',
        };

        $features = collect($aiSpec['core_features'] ?? [])->map(fn ($f) => "- {$f}")->implode("\n");
        $nfrs = collect($aiSpec['non_functional_requirements'] ?? [])->map(fn ($f) => "- {$f}")->implode("\n");
        $moduleCount = count($aiSpec['modules'] ?? []);
        $subCount = collect($aiSpec['modules'] ?? [])
            ->sum(fn ($m) => count($m['submodules'] ?? []));

        $stack = 'Laravel 13 + PHP 8.3 (Backend), Livewire 4 + Alpine.js v3 + Tailwind CSS v4 (Frontend), Filament v5 (Admin), PostgreSQL 16 (Banco de Dados)';

        return <<<PROMPT
Estime as horas de trabalho por área para o seguinte projeto de software:

PROJETO: {$name}
COMPLEXIDADE: {$complexity}
STACK: {$stack}
MÓDULOS: {$moduleCount} módulos principais / {$subCount} submódulos
OBJETIVO: {$objective}

FUNCIONALIDADES PRINCIPAIS:
{$features}

REQUISITOS NÃO-FUNCIONAIS:
{$nfrs}

Retorne APENAS este JSON com os valores inteiros de horas estimadas (use 0 se a área não se aplica):
{
  "backend_hours": 0,
  "frontend_hours": 0,
  "mobile_hours": 0,
  "database_hours": 0,
  "devops_hours": 0,
  "design_hours": 0,
  "testing_hours": 0,
  "security_hours": 0,
  "pm_hours": 0,
  "justification": "Breve justificativa das estimativas"
}
PROMPT;
    }

    private function inferComplexityLevel(array $aiSpec): int
    {
        $estimated = strtolower($aiSpec['estimated_complexity'] ?? '');
        $moduleCount = count($aiSpec['modules'] ?? []);
        $subCount = collect($aiSpec['modules'] ?? [])->sum(fn ($m) => count($m['submodules'] ?? []));

        if (str_contains($estimated, 'simple') || str_contains($estimated, 'simpl') || ($moduleCount <= 3 && $subCount <= 8)) {
            return 1;
        }

        if (str_contains($estimated, 'enterprise') || $moduleCount >= 8 || $subCount >= 30) {
            return 4;
        }

        if (str_contains($estimated, 'complex') || $moduleCount >= 5 || $subCount >= 15) {
            return 3;
        }

        return 2; // Médio por padrão
    }

    /** @return array<string, bool> */
    private function inferRequiredAreas(array $hours): array
    {
        return [
            'backend' => ($hours['backend_hours'] ?? 0) > 0,
            'frontend' => ($hours['frontend_hours'] ?? 0) > 0,
            'mobile' => ($hours['mobile_hours'] ?? 0) > 0,
            'database' => ($hours['database_hours'] ?? 0) > 0,
            'devops' => ($hours['devops_hours'] ?? 0) > 0,
            'design' => ($hours['design_hours'] ?? 0) > 0,
            'testing' => ($hours['testing_hours'] ?? 0) > 0,
            'security' => ($hours['security_hours'] ?? 0) > 0,
            'pm' => ($hours['pm_hours'] ?? 0) > 0,
        ];
    }
}
