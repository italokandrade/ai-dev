<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Filament\Resources\ProjectModuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use App\Models\ProjectModule;

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

        return $query;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $baseClasses = "text-primary-600 hover:text-primary-500 hover:underline transition font-semibold cursor-pointer";

        if (!$this->activeModuleId) {
            return new HtmlString('<div class="flex items-center gap-2 text-sm text-gray-500 mt-2">
                <span class="font-medium text-gray-700">📍 Navegação:</span> 
                <span class="text-primary-600 font-semibold bg-primary-50 px-2 py-1 rounded-md">Raiz</span>
            </div>');
        }

        $module = ProjectModule::find($this->activeModuleId);
        if (!$module) return null;

        $path = [];
        $current = $module;
        while ($current) {
            $isActive = $current->id === $this->activeModuleId;
            if ($isActive) {
                $path[] = "<span class=\"text-primary-700 font-bold bg-primary-50 px-2 py-1 rounded-md\">{$current->name}</span>";
            } else {
                $path[] = "<a href='#' wire:click.prevent=\"setActiveModule('{$current->id}')\" wire:loading.attr=\"disabled\" class=\"{$baseClasses}\">{$current->name}</a>";
            }
            $current = $current->parent;
        }
        $path[] = "<a href='#' wire:click.prevent=\"setActiveModule(null)\" wire:loading.attr=\"disabled\" class=\"{$baseClasses}\">Raiz</a>";

        $path = array_reverse($path);
        
        return new HtmlString('<div class="flex items-center gap-2 text-sm text-gray-500 mt-2 flex-wrap">
            <span class="font-medium text-gray-700">📍 Navegação:</span> 
            ' . implode(' <span class="text-gray-300">/</span> ', $path) . '
        </div>');
    }
}
