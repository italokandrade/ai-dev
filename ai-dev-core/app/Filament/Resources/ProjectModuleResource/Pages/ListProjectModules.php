<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Filament\Resources\ProjectModuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

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
}
