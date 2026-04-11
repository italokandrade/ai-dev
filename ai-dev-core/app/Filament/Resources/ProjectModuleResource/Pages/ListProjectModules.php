<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Filament\Resources\ProjectModuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjectModules extends ListRecords
{
    protected static string $resource = ProjectModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Módulo'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pai' => Tab::make('Módulos Pai')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('parent_id')),
            'filho' => Tab::make('Submódulos')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('parent_id')),
            'all' => Tab::make('Todos'),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'pai';
    }
}
