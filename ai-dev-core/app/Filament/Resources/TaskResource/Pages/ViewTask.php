<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Coluna esquerda: visão geral + PRD
                \Filament\Schemas\Components\Group::make([
                    Section::make('Visão Geral')
                        ->schema([
                            Infolists\Components\TextEntry::make('title')
                                ->label('Título')
                                ->weight('bold')
                                ->columnSpanFull(),

                            Grid::make(3)
                                ->schema([
                                    Infolists\Components\TextEntry::make('project.name')
                                        ->label('Projeto'),

                                    Infolists\Components\TextEntry::make('module.name')
                                        ->label('Módulo')
                                        ->placeholder('Avulsa'),

                                    Infolists\Components\TextEntry::make('status')
                                        ->label('Status')
                                        ->badge(),
                                ]),

                            Grid::make(3)
                                ->schema([
                                    Infolists\Components\TextEntry::make('priority')
                                        ->label('Prioridade')
                                        ->badge(),

                                    Infolists\Components\TextEntry::make('source')
                                        ->label('Origem')
                                        ->badge(),

                                    Infolists\Components\TextEntry::make('retry_count')
                                        ->label('Retentativas')
                                        ->formatStateUsing(fn (Task $record) => "{$record->retry_count}/{$record->max_retries}"),
                                ]),
                        ]),

                    Section::make('PRD — Product Requirement Document')
                        ->schema([
                            Infolists\Components\TextEntry::make('prd_payload.objective')
                                ->label('Objetivo')
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('prd_payload.acceptance_criteria')
                                ->label('Critérios de Aceite')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('prd_payload.constraints')
                                ->label('Restrições Técnicas')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->placeholder('Nenhuma restrição definida.')
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('prd_payload.knowledge_areas')
                                ->label('Áreas de Conhecimento')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ]),
                ])->columnSpan(['default' => 1, 'xl' => 1]),

                // Coluna direita: execução + histórico
                \Filament\Schemas\Components\Group::make([
                    Section::make('Execução')
                        ->schema([
                            Infolists\Components\TextEntry::make('assigned_agent_id')
                                ->label('Agente Designado')
                                ->placeholder('Nenhum'),

                            Infolists\Components\TextEntry::make('git_branch')
                                ->label('Branch Git')
                                ->placeholder('—')
                                ->copyable(),

                            Infolists\Components\TextEntry::make('commit_hash')
                                ->label('Commit Hash')
                                ->placeholder('—')
                                ->copyable(),

                            Infolists\Components\TextEntry::make('started_at')
                                ->label('Iniciada em')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('completed_at')
                                ->label('Concluída em')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('created_at')
                                ->label('Criada em')
                                ->dateTime('d/m/Y H:i'),
                        ])
                        ->columns(2),

                    Section::make('Histórico de Transições')
                        ->schema([
                            Infolists\Components\RepeatableEntry::make('transitions')
                                ->label('')
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            Infolists\Components\TextEntry::make('from_status')
                                                ->label('De')
                                                ->badge()
                                                ->placeholder('—'),

                                            Infolists\Components\TextEntry::make('to_status')
                                                ->label('Para')
                                                ->badge(),

                                            Infolists\Components\TextEntry::make('triggered_by')
                                                ->label('Disparado por'),

                                            Infolists\Components\TextEntry::make('created_at')
                                                ->label('Quando')
                                                ->dateTime('d/m/Y H:i'),
                                        ]),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),

                    Section::make('Log de Erros')
                        ->schema([
                            Infolists\Components\TextEntry::make('error_log')
                                ->label('')
                                ->placeholder('Nenhum erro registrado.')
                                ->columnSpanFull(),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ])->columnSpan(['default' => 1, 'xl' => 1]),
            ])
            ->columns(['default' => 1, 'xl' => 2]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // Refazer task (redo) — cria nova task vinculada
            Actions\Action::make('redo')
                ->label('Refazer Task')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Refazer esta Task')
                ->modalDescription('Será criada uma nova task vinculada a esta, com o mesmo PRD. Use quando a IA falhou ou o resultado precisa ser refeito.')
                ->action(function () {
                    /** @var Task $task */
                    $task = $this->record;

                    $redo = $task->redo();

                    if ($redo->id === $task->id) {
                        Notification::make()
                            ->title('Não é possível refazer')
                            ->body('Esta task ainda não foi concluída ou está em processamento.')
                            ->warning()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title('Task recriada com sucesso')
                        ->body('A task foi enviada para a fila de processamento.')
                        ->success()
                        ->send();

                    $this->redirect(TaskResource::getUrl('view', ['record' => $redo]));
                })
                ->visible(fn () => in_array($this->record->status, [TaskStatus::Completed, TaskStatus::Failed])),
        ];
    }
}
