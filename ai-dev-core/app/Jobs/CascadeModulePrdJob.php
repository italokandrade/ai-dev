<?php

namespace App\Jobs;

use App\Ai\Agents\ModulePrdAgent;
use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Models\ProjectModule;
use App\Services\AiRuntimeConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CascadeModulePrdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 660;

    public function __construct(
        public ProjectModule $module,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        $this->module->refresh();

        // Se já tem PRD válido, pula geração e vai direto para auto-aprovação
        $existingPrd = $this->module->prd_payload;
        if (!empty($existingPrd) && empty($existingPrd['_status'] ?? '')) {
            Log::info("CascadeModulePrdJob: PRD já existe para '{$this->module->name}', pulando geração.");
            $this->autoApprove($existingPrd);
            return;
        }

        Log::info("CascadeModulePrdJob: Gerando PRD para '{$this->module->name}'");

        $prdPayload = $this->generatePrd();

        $this->module->update(['prd_payload' => $prdPayload]);

        if (!empty($prdPayload['_status'])) {
            Log::error("CascadeModulePrdJob: PRD falhou para '{$this->module->name}', cascata interrompida neste ramo.");
            return;
        }

        $this->autoApprove($prdPayload);
    }

    private function generatePrd(): array
    {
        $prompt = $this->buildPrompt();

        try {
            $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

            $response = (new ModulePrdAgent())
                ->prompt(
                    $prompt,
                    provider: $aiConfig['provider'],
                    model: $aiConfig['model'],
                );

            $prdPayload = $this->parsePrd((string) $response);

            Log::info("CascadeModulePrdJob: PRD gerado com sucesso para '{$this->module->name}'");

            return $prdPayload;
        } catch (\Throwable $e) {
            Log::error("CascadeModulePrdJob: Falha na geração do PRD", [
                'module' => $this->module->name,
                'error'  => $e->getMessage(),
            ]);

            return $this->fallbackPrd($e->getMessage());
        }
    }

    private function autoApprove(array $prd): void
    {
        if ($prd['needs_submodules'] ?? false) {
            $this->createSubmodules($prd);
        } else {
            $this->createTasks($prd);
        }
    }

    private function createSubmodules(array $prd): void
    {
        if (empty($prd['submodules'])) {
            return;
        }

        // Se já tem filhos criados, apenas despacha cascata para eles
        $existingChildren = $this->module->children()->get();
        if ($existingChildren->isNotEmpty()) {
            Log::info("CascadeModulePrdJob: Submódulos já existem para '{$this->module->name}'. Despachando cascata para os existentes.");
            foreach ($existingChildren as $child) {
                self::dispatch($child);
            }
            return;
        }

        $created = [];

        foreach ($prd['submodules'] as $submoduleData) {
            $priorityEnum = match ($submoduleData['priority'] ?? 'normal') {
                'high'   => Priority::High,
                'medium' => Priority::Medium,
                default  => Priority::Normal,
            };

            $name = $submoduleData['name'] ?? '';
            if (is_array($name)) {
                $name = implode(' ', $name);
            }
            $description = $submoduleData['description'] ?? '';
            if (is_array($description)) {
                $description = implode(' ', $description);
            }

            $submodule = ProjectModule::create([
                'project_id'  => $this->module->project_id,
                'parent_id'   => $this->module->id,
                'name'        => $name,
                'description' => $description,
                'status'      => ModuleStatus::Planned,
                'priority'    => $priorityEnum,
            ]);

            $created[] = $submodule;
        }

        Log::info("CascadeModulePrdJob: " . count($created) . " submódulos criados para '{$this->module->name}'. Despachando cascata.");

        foreach ($created as $submodule) {
            self::dispatch($submodule);
        }
    }

    private function createTasks(array $prd): void
    {
        // Se já tem tasks, não duplicar
        if ($this->module->tasks()->exists()) {
            Log::info("CascadeModulePrdJob: Tasks já existem para '{$this->module->name}'. Nada a fazer.");
            return;
        }

        $tasks = [];

        foreach ($prd['components'] ?? [] as $component) {
            $tasks[] = [
                'title'       => "Implementar {$component['type']}: {$component['name']}",
                'description' => $component['description'] ?? '',
                'priority'    => \App\Enums\Priority::High,
            ];
        }

        foreach ($prd['workflows'] ?? [] as $workflow) {
            $steps = collect($workflow['steps'] ?? [])
                ->map(fn ($s) => is_array($s) ? ($s['name'] ?? json_encode($s)) : $s)
                ->implode(' → ');
            $tasks[] = [
                'title'       => "Fluxo: {$workflow['name']}",
                'description' => 'Steps: ' . $steps,
                'priority'    => \App\Enums\Priority::High,
            ];
        }

        foreach ($prd['api_endpoints'] ?? [] as $api) {
            $tasks[] = [
                'title'       => "API {$api['method']} {$api['uri']}",
                'description' => $api['description'] ?? '',
                'priority'    => \App\Enums\Priority::Medium,
            ];
        }

        foreach ($prd['database_schema']['tables'] ?? [] as $table) {
            $tasks[] = [
                'title'       => "Migration: {$table['name']}",
                'description' => $table['description'] ?? '',
                'priority'    => \App\Enums\Priority::High,
            ];
        }

        foreach ($prd['acceptance_criteria'] ?? [] as $criteria) {
            $tasks[] = [
                'title'       => "Teste: " . (is_array($criteria) ? json_encode($criteria) : $criteria),
                'description' => is_array($criteria) ? json_encode($criteria) : $criteria,
                'priority'    => \App\Enums\Priority::Medium,
            ];
        }

        foreach ($tasks as $taskData) {
            \App\Models\Task::create([
                'project_id'  => $this->module->project_id,
                'module_id'   => $this->module->id,
                'title'       => $taskData['title'],
                'description' => $taskData['description'],
                'status'      => \App\Enums\TaskStatus::Pending,
                'priority'    => $taskData['priority'],
                'source'      => \App\Enums\TaskSource::Specification,
                'max_retries' => 3,
            ]);
        }

        Log::info("CascadeModulePrdJob: " . count($tasks) . " tasks criadas para '{$this->module->name}'");
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
            $projectDescription = substr($projectDescription, 0, 1000) . "\n\n[...descrição truncada...]";
        }

        $typeLabel = $isSubmodule ? 'SUBMÓDULO' : 'MÓDULO';
        $parentInfo = $isSubmodule ? "\nMÓDULO PAI: {$parentName}\n" : '';

        return <<<PROMPT
PROJETO: {$project->name}

DESCRIÇÃO DO PROJETO:
{$projectDescription}

{$typeLabel}: {$moduleName}
{$parentInfo}
DESCRIÇÃO DO {$typeLabel}:
{$moduleDescription}

---
INSTRUÇÃO: Gere o PRD Técnico deste {$typeLabel}.
Este PRD será usado por desenvolvedores para implementar o código.
Especifique: tabelas de banco, APIs, componentes, regras de negócio, validações, permissões e fluxos.
PROMPT;
    }

    private function parsePrd(string $raw): array
    {
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $data = json_decode(trim($clean), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('CascadeModulePrdJob: Falha ao parsear JSON da IA', [
                'raw_preview' => substr($raw, 0, 300),
                'json_error'  => json_last_error_msg(),
            ]);
            throw new \RuntimeException('JSON inválido retornado pela IA: ' . json_last_error_msg());
        }

        return $data;
    }

    private function fallbackPrd(string $error): array
    {
        return [
            'title'     => "{$this->module->name} — PRD Técnico",
            'objective' => 'Geração automática do PRD falhou. Por favor, revise os logs e tente novamente.',
            '_status'   => 'ai_generation_failed',
            '_error'    => $error,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("CascadeModulePrdJob: Job falhou por completo", [
            'module' => $this->module->name,
            'error'  => $exception->getMessage(),
        ]);

        // Só salva fallback se não houver PRD válido já salvo
        $this->module->refresh();
        $current = $this->module->prd_payload;
        if (empty($current) || !empty($current['_status'] ?? '')) {
            $this->module->update([
                'prd_payload' => $this->fallbackPrd($exception->getMessage()),
            ]);
        }
    }
}
