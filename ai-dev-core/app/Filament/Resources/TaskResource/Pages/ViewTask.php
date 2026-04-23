<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Components\NavigationTree;
use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
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

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return NavigationTree::forTask($this->record);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Visão Geral')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->label('Título')
                            ->weight('bold')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('project.name')
                                    ->label('Projeto')
                                    ->url(fn () => ProjectResource::getUrl('view', ['record' => $this->record->project_id]))
                                    ->openUrlInNewTab(false),

                                Infolists\Components\TextEntry::make('module.name')
                                    ->label('Módulo')
                                    ->placeholder('Avulsa')
                                    ->url(fn ($state) => $state && $this->record->module_id ? ProjectModuleResource::getUrl('view', ['record' => $this->record->module_id]) : null)
                                    ->openUrlInNewTab(false),

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

                Section::make('Subtasks')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('subtasks')
                            ->label('')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('execution_order')
                                            ->label('#')
                                            ->alignCenter(),

                                        Infolists\Components\TextEntry::make('title')
                                            ->label('Título')
                                            ->columnSpan(2),

                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Status')
                                            ->badge(),
                                    ]),

                                Grid::make(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('assigned_agent')
                                            ->label('Agente')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('commit_hash')
                                            ->label('Commit')
                                            ->placeholder('—')
                                            ->copyable(),

                                        Infolists\Components\TextEntry::make('started_at')
                                            ->label('Iniciada')
                                            ->dateTime('d/m/Y H:i')
                                            ->placeholder('—'),
                                    ]),

                                Infolists\Components\TextEntry::make('result_log')
                                    ->label('Log de Resultado')
                                    ->placeholder('Sem log.')
                                    ->columnSpanFull(),

                                Infolists\Components\TextEntry::make('qa_feedback')
                                    ->label('Feedback QA')
                                    ->placeholder('Sem feedback.')
                                    ->columnSpanFull(),
                            ])
                            ->placeholder('Nenhuma subtask gerada ainda.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

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
            ])
            ->columns(1);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // Refazer task (redo) — cria nova task vinculada
            Actions\Action::make('redo')
                ->label('Refazer Task')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
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
