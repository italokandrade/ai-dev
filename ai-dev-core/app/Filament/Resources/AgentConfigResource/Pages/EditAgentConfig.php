<?php

namespace App\Filament\Resources\AgentConfigResource\Pages;

use App\Filament\Resources\AgentConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentConfig extends EditRecord
{
    protected static string $resource = AgentConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
