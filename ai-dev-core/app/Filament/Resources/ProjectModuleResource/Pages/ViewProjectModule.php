<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Enums\ModuleStatus;
use App\Filament\Components\NavigationTree;
use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateModulePrdJob;
use App\Jobs\GenerateModuleSubmodulesJob;
use App\Jobs\GenerateModuleTasksJob;
use App\Models\ProjectModule;
use Filament\Actions;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewProjectModule extends ViewRecord
{
    protected static string $resource = ProjectModuleResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return ProjectModule::with([
            'project',
            'parent',
            'children' => fn ($q) => $q->withCount('tasks'),
        ])->findOrFail($key);
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return NavigationTree::forModule($this->record);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Visão Geral')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('project.name')
                                    ->label('Projeto')
                                    ->weight('bold')
                                    ->url(fn () => ProjectResource::getUrl('view', ['record' => $this->record->project_id]))
                                    ->openUrlInNewTab(false),

                                Infolists\Components\TextEntry::make('parent.name')
                                    ->label('Módulo Pai')
                                    ->placeholder('Módulo Raiz')
                                    ->url(fn ($state) => $state ? ProjectModuleResource::getUrl('view', ['record' => $this->record->parent_id]) : null)
                                    ->openUrlInNewTab(false),

                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge(),

                                Infolists\Components\TextEntry::make('priority')
                                    ->label('Prioridade')
                                    ->badge(),
                            ]),

                        Infolists\Components\TextEntry::make('description')
                            ->label('Descrição')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('progress_percentage')
                                    ->label('Progresso')
                                    ->formatStateUsing(fn ($state) => $state . '%')
                                    ->color(fn ($state) => match (true) {
                                        $state >= 80 => 'success',
                                        $state >= 40 => 'warning',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('started_at')
                                    ->label('Iniciado em')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('completed_at')
                                    ->label('Concluído em')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('—'),
                            ]),
                    ]),

                Section::make('Submódulos')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('children')
                            ->label('')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('name')
                                            ->label('Submódulo')
                                            ->weight('bold')
                                            ->icon('heroicon-o-document-text')
                                            ->url(fn (ProjectModule $record) => ProjectModuleResource::getUrl('view', ['record' => $record]))
                                            ->openUrlInNewTab(false),

                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Status')
                                            ->badge(),

                                        Infolists\Components\TextEntry::make('progress_percentage')
                                            ->label('Progresso')
                                            ->formatStateUsing(fn ($state) => $state . '%'),

                                        Infolists\Components\TextEntry::make('tasks_count')
                                            ->label('Tasks'),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->visible(fn () => $this->record->children()->exists()),

                Section::make('PRD do Módulo')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('prd_payload.title')
                                    ->label('Título')
                                    ->weight('bold')
                                    ->placeholder('PRD ainda não gerado'),

                                Infolists\Components\TextEntry::make('prd_payload.estimated_complexity')
                                    ->label('Complexidade')
                                    ->badge()
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('prd_payload.estimated_hours')
                                    ->label('Horas Estimadas')
                                    ->formatStateUsing(fn ($state) => $state ? "{$state}h" : '—')
                                    ->placeholder('—'),
                            ]),

                        Infolists\Components\TextEntry::make('prd_payload.objective')
                            ->label('Objetivo')
                            ->columnSpanFull()
                            ->placeholder('—'),

                        Infolists\Components\KeyValueEntry::make('prd_payload.business_rules')
                            ->label('Regras de Negócio')
                            ->columnSpanFull()
                            ->placeholder('Nenhuma regra definida')
                            ->visible(fn () => !empty($this->record->prd_payload['business_rules'])),

                        Infolists\Components\KeyValueEntry::make('prd_payload.acceptance_criteria')
                            ->label('Critérios de Aceitação')
                            ->columnSpanFull()
                            ->placeholder('Nenhum critério definido')
                            ->visible(fn () => !empty($this->record->prd_payload['acceptance_criteria'])),
                    ])
                    ->collapsible()
                    ->visible(fn () => !empty($this->record->prd_payload)),
            ])
            ->columns(1);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('transition_in_progress')
                ->label('Iniciar')
                ->icon('heroicon-o-play')
                ->color('info')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $this->record->transitionTo(ModuleStatus::InProgress);
                        Notification::make()->title('Módulo iniciado')->success()->send();
                        $this->refreshFormData(['status', 'started_at']);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => $this->record->status === ModuleStatus::Planned),

            Actions\Action::make('transition_completed')
                ->label('Marcar como Concluído')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $this->record->transitionTo(ModuleStatus::Completed);
                        Notification::make()->title('Módulo concluído!')->success()->send();
                        $this->refreshFormData(['status', 'completed_at']);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => in_array($this->record->status, [ModuleStatus::InProgress, ModuleStatus::Testing])),

            Actions\Action::make('generateModulePrd')
                ->label('Gerar PRD do Módulo')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Gerar PRD Técnico do Módulo')
                ->modalDescription('A IA irá gerar um PRD detalhado e técnico para este módulo. Isso pode levar alguns minutos.')
                ->modalSubmitActionLabel('Gerar PRD')
                ->action(function () {
                    GenerateModulePrdJob::dispatch($this->record);

                    Notification::make()
                        ->title('Geração do PRD iniciada')
                        ->body('O PRD técnico do módulo está sendo gerado em background. Recarregue a página em alguns instantes.')
                        ->success()
                        ->send();
                })
                ->visible(fn () => empty($this->record->prd_payload) || !empty($this->record->prd_payload['_status'] ?? '')),

            Actions\Action::make('viewFullModulePrd')
                ->label('Ver PRD Completo')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->modalHeading(fn () => "PRD Técnico — {$this->record->name}")
                ->modalContent(fn () => view('filament.module-prd-viewer', ['prd' => $this->record->prd_payload]))
                ->modalWidth('7xl')
                ->visible(fn () => !empty($this->record->prd_payload) && empty($this->record->prd_payload['_status'] ?? '')),

            Actions\Action::make('createSubmodules')
                ->label('✅ Aprovar PRD — Criar Submódulos')
                ->icon('heroicon-o-squares-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD e Criar Submódulos')
                ->modalDescription('O PRD indica que este módulo possui submódulos. Ao aprovar, os submódulos definidos serão criados. Cada submódulo precisará de seu próprio PRD para gerar tasks ou sub-submódulos.')
                ->modalSubmitActionLabel('Aprovar e Criar Submódulos')
                ->action(function () {
                    GenerateModuleSubmodulesJob::dispatch($this->record);
                    Notification::make()
                        ->title('PRD aprovado — Submódulos sendo criados')
                        ->body('Os submódulos estão sendo criados. Acesse cada um para gerar seu PRD individual.')
                        ->success()
                        ->send();
                })
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && ($this->record->prd_payload['needs_submodules'] ?? false)
                    && !$this->record->children()->exists()
                ),

            Actions\Action::make('createTasks')
                ->label('✅ Aprovar PRD — Criar Tasks')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD e Criar Tasks')
                ->modalDescription('O PRD indica que este módulo não possui submódulos. Ao aprovar, as tasks de desenvolvimento serão criadas automaticamente a partir dos componentes, APIs, migrations e critérios definidos no PRD.')
                ->modalSubmitActionLabel('Aprovar e Criar Tasks')
                ->action(function () {
                    GenerateModuleTasksJob::dispatch($this->record);
                    Notification::make()
                        ->title('PRD aprovado — Tasks sendo criadas')
                        ->body('As tasks estão sendo geradas em background. Recarregue a página em instantes.')
                        ->success()
                        ->send();
                })
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && !($this->record->prd_payload['needs_submodules'] ?? false)
                    && !$this->record->tasks()->exists()
                ),
        ];
    }
}
