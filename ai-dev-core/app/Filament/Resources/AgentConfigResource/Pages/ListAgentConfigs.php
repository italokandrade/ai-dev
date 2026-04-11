<?php

namespace App\Filament\Resources\AgentConfigResource\Pages;

use App\Filament\Resources\AgentConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentConfigs extends ListRecords
{
    protected static string $resource = AgentConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Agente'),
        ];
    }
}
