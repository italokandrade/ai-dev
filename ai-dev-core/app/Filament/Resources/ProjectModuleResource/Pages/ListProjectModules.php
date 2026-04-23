<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Models\ProjectModule;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListProjectModules extends ListRecords
{
    protected static string $resource = ProjectModuleResource::class;

    public ?string $activeModuleId = null;

    protected $queryString = [
        'activeModuleId' => ['except' => '', 'as' => 'parent'],
    ];

    public function setActiveModule(?string $moduleId = null): void
    {
        $this->activeModuleId = $moduleId;
        $this->resetPage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Módulo')
                ->mutateFormDataUsing(function (array $data): array {
                    if ($this->activeModuleId && empty($data['parent_id'])) {
                        $data['parent_id'] = $this->activeModuleId;
                    }
                    return $data;
                }),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        
        if ($this->activeModuleId) {
            $query->where('parent_id', $this->activeModuleId);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->withCount('children');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        if ($this->activeModuleId) {
            $module = ProjectModule::find($this->activeModuleId);
            if ($module) {
                return "Submódulos de: {$module->name}";
            }
        }
        return 'Módulos';
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $baseClasses = "text-primary-600 hover:text-primary-500 hover:underline transition font-semibold cursor-pointer";

        if (!$this->activeModuleId) {
            return new HtmlString('<div class="flex items-center gap-2 text-sm text-gray-500 mt-2">
                <span class="font-medium text-gray-700">📍 Navegação:</span>
                <span class="text-primary-600 font-semibold bg-primary-50 px-2 py-1 rounded-md">Todos os Módulos</span>
            </div>');
        }

        $module = ProjectModule::with(['project'])->find($this->activeModuleId);
        if (!$module) return null;

        $path = [];

        // Link para o projeto
        $projectUrl = ProjectResource::getUrl('view', ['record' => $module->project_id]);
        $path[] = "<a href='{$projectUrl}' class=\"{$baseClasses}\">📁 " . e($module->project->name) . "</a>";

        $current = $module;
        while ($current) {
            $isActive = $current->id === $this->activeModuleId;
            if ($isActive) {
                $path[] = "<span class=\"text-primary-700 font-bold bg-primary-50 px-2 py-1 rounded-md\">" . e($current->name) . "</span>";
            } else {
                $path[] = "<a href='#' wire:click.prevent=\"setActiveModule('{$current->id}')\" wire:loading.attr=\"disabled\" class=\"{$baseClasses}\">" . e($current->name) . "</a>";
            }
            $current = $current->parent;
        }
        $path[] = "<a href='#' wire:click.prevent=\"setActiveModule(null)\" wire:loading.attr=\"disabled\" class=\"{$baseClasses}\">🔲 Módulos</a>";

        $path = array_reverse($path);

        return new HtmlString('<div class="flex items-center gap-2 text-sm text-gray-500 mt-2 flex-wrap">
            <span class="font-medium text-gray-700">📍 Navegação:</span>
            ' . implode(' <span class="text-gray-300">/</span> ', $path) . '
        </div>');
    }
}
