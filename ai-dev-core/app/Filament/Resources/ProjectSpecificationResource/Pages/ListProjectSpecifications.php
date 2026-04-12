<?php

namespace App\Filament\Resources\ProjectSpecificationResource\Pages;

use App\Filament\Resources\ProjectSpecificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectSpecifications extends ListRecords
{
    protected static string $resource = ProjectSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nova Especificação'),
        ];
    }
}
