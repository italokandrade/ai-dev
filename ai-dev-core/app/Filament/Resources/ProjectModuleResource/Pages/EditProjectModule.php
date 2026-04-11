<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Filament\Resources\ProjectModuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProjectModule extends EditRecord
{
    protected static string $resource = ProjectModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
