<?php

namespace App\Jobs;

use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Models\ProjectModule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateModuleSubmodulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int MAX_SUBMODULES_PER_MODULE = 8;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public ProjectModule $module,
    ) {
        $this->onQueue('orchestrator');
    }

    public function handle(): void
    {
        Log::info("GenerateModuleSubmodulesJob: Criando submódulos para '{$this->module->name}'");

        $prd = $this->module->prd_payload;

        if (empty($prd) || empty($prd['submodules'])) {
            Log::info('GenerateModuleSubmodulesJob: Nenhum submódulo definido no PRD.');

            return;
        }

        $created = 0;
        $existingNames = $this->module->children()
            ->pluck('name')
            ->map(fn (string $name): string => $this->normalizeName($name))
            ->all();
        $seenNames = array_fill_keys($existingNames, true);

        foreach ($prd['submodules'] as $submoduleData) {
            if ($created >= self::MAX_SUBMODULES_PER_MODULE || ! is_array($submoduleData)) {
                break;
            }

            $name = $this->stringValue($submoduleData['name'] ?? '');
            $normalizedName = $this->normalizeName($name);
            if ($name === '' || isset($seenNames[$normalizedName])) {
                continue;
            }

            $priorityEnum = match ($submoduleData['priority'] ?? 'normal') {
                'high' => Priority::High,
                'medium' => Priority::Medium,
                default => Priority::Normal,
            };

            ProjectModule::create([
                'project_id' => $this->module->project_id,
                'parent_id' => $this->module->id,
                'name' => $name,
                'description' => $this->stringValue($submoduleData['description'] ?? ''),
                'status' => ModuleStatus::Planned,
                'priority' => $priorityEnum,
                'dependencies' => null,
            ]);

            $created++;
            $seenNames[$normalizedName] = true;
        }

        Log::info("GenerateModuleSubmodulesJob: {$created} submódulos criados para '{$this->module->name}'");
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map($this->stringValue(...), $value)));
        }

        return trim((string) $value);
    }
}
