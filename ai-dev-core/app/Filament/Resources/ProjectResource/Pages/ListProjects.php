<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->withCount([
            'modules as root_modules_count' => fn ($q) => $q->whereNull('parent_id'),
            'modules as sub_modules_count' => fn ($q) => $q->whereNotNull('parent_id'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Projeto'),
        ];
    }
}
