<?php

namespace App\Filament\Resources\AgentConfigResource\Pages;

use App\Filament\Resources\AgentConfigResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAgentConfig extends ViewRecord
{
    protected static string $resource = AgentConfigResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Infolists\Components\Section::make('Identificacao')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('display_name')
                            ->label('Nome'),

                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Ativo')
                            ->boolean(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Modelo de IA')
                    ->schema([
                        Infolists\Components\TextEntry::make('provider')
                            ->label('Provider')
                            ->badge(),

                        Infolists\Components\TextEntry::make('model')
                            ->label('Modelo'),

                        Infolists\Components\TextEntry::make('api_key_env_var')
                            ->label('Env Var')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('temperature')
                            ->label('Temperature'),

                        Infolists\Components\TextEntry::make('max_tokens')
                            ->label('Max Tokens'),

                        Infolists\Components\TextEntry::make('max_parallel_tasks')
                            ->label('Tasks Paralelas'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Funcao')
                    ->schema([
                        Infolists\Components\TextEntry::make('role_description')
                            ->label('Descricao do Papel')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('knowledge_areas')
                            ->label('Areas de Conhecimento')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('fallbackAgent.display_name')
                            ->label('Agente de Fallback')
                            ->placeholder('Nenhum'),

                        Infolists\Components\TextEntry::make('assigned_tasks_count')
                            ->label('Tasks Atribuidas')
                            ->state(fn ($record) => $record->assignedTasks()->count()),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
