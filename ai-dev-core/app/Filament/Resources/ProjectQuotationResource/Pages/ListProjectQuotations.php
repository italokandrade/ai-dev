<?php

namespace App\Filament\Resources\ProjectQuotationResource\Pages;

use App\Filament\Resources\ProjectQuotationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectQuotations extends ListRecords
{
    protected static string $resource = ProjectQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
