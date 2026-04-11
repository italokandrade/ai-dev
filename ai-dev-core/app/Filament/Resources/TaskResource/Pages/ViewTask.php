<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Schemas\Components\Section;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Visao Geral')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->label('Titulo')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('project.name')
                            ->label('Projeto'),

                        Infolists\Components\TextEntry::make('module.name')
                            ->label('Modulo')
                            ->placeholder('Avulsa'),

                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge(),

                        Infolists\Components\TextEntry::make('priority')
                            ->label('Prioridade'),

                        Infolists\Components\TextEntry::make('source')
                            ->label('Origem')
                            ->badge(),

                        Infolists\Components\TextEntry::make('assigned_agent_id')
                            ->label('Agente')
                            ->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('PRD')
                    ->schema([
                        Infolists\Components\TextEntry::make('prd_payload.objective')
                            ->label('Objetivo')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('prd_payload.acceptance_criteria')
                            ->label('Criterios de Aceite')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('prd_payload.constraints')
                            ->label('Restricoes Tecnicas')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('prd_payload.knowledge_areas')
                            ->label('Areas de Conhecimento')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                    ]),

                Section::make('Execucao')
                    ->schema([
                        Infolists\Components\TextEntry::make('git_branch')
                            ->label('Branch')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('commit_hash')
                            ->label('Commit')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('retry_count')
                            ->label('Retentativas')
                            ->formatStateUsing(fn ($record) => "{$record->retry_count}/{$record->max_retries}"),

                        Infolists\Components\TextEntry::make('started_at')
                            ->label('Iniciada em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('completed_at')
                            ->label('Concluida em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Criada em')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),

                Section::make('Log de Erros')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_log')
                            ->label('')
                            ->placeholder('Nenhum erro registrado.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
