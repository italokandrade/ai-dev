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
                                    ->placeholder('Raiz')
                                    ->url(fn ($state) => $state ? ProjectModuleResource::getUrl('view', ['record' => $this->record->parent_id]) : null)
                                    ->openUrlInNewTab(false),

                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge(),

                                Infolists\Components\TextEntry::make('priority')
                                    ->label('Prioridade')
                                    ->badge(),
                            ]),

                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('progress_percentage')
                                    ->label('Progresso')
                                    ->formatStateUsing(fn ($state) => $state.'%'),

                                Infolists\Components\TextEntry::make('started_at')
                                    ->label('Iniciado em')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('completed_at')
                                    ->label('Concluído em')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('—'),
                            ]),

                        Infolists\Components\TextEntry::make('description')
                            ->label('Descrição')
                            ->columnSpanFull(),
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
                                            ->formatStateUsing(fn ($state) => $state.'%'),

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
                                    ->placeholder('PRD não gerado'),

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

            Actions\Action::make('transition')
                ->label(fn () => $this->record->status === ModuleStatus::Planned ? 'Iniciar' : 'Marcar como Concluído')
                ->icon(fn () => $this->record->status === ModuleStatus::Planned ? 'heroicon-o-play' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->status === ModuleStatus::Planned ? 'primary' : 'success')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $target = $this->record->status === ModuleStatus::Planned
                            ? ModuleStatus::InProgress
                            : ModuleStatus::Completed;

                        $this->record->transitionTo($target);

                        Notification::make()
                            ->title($target === ModuleStatus::InProgress ? 'Módulo iniciado' : 'Módulo concluído!')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'started_at', 'completed_at']);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => in_array($this->record->status, [ModuleStatus::Planned, ModuleStatus::InProgress, ModuleStatus::Testing])),

            Actions\Action::make('generateModulePrd')
                ->label('Gerar PRD')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Gerar PRD do Módulo')
                ->modalDescription('A IA irá gerar um PRD técnico detalhado para este módulo.')
                ->modalSubmitActionLabel('Gerar')
                ->action(function () {
                    $this->record->update(['prd_payload' => ['_status' => 'generating']]);
                    GenerateModulePrdJob::dispatch($this->record->fresh());
                    Notification::make()
                        ->title('PRD sendo gerado...')
                        ->body('O botão será atualizado quando concluído.')
                        ->success()
                        ->send();
                })
                ->visible(fn () =>
                    empty($this->record->prd_payload)
                    || ($this->record->prd_payload['_status'] ?? '') === 'ai_generation_failed'
                ),

            Actions\Action::make('generatingModulePrd')
                ->label('Gerando PRD...')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->disabled()
                ->visible(fn () => ($this->record->prd_payload['_status'] ?? '') === 'generating'),

            Actions\Action::make('approveModulePrd')
                ->label('Aprovar PRD')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD do Módulo')
                ->modalDescription(fn () => ($this->record->prd_payload['needs_submodules'] ?? false)
                    ? 'Os submódulos definidos no PRD serão criados automaticamente.'
                    : 'As tasks de desenvolvimento serão criadas automaticamente a partir do PRD.')
                ->modalSubmitActionLabel('Aprovar e Criar')
                ->action(function () {
                    if ($this->record->prd_payload['needs_submodules'] ?? false) {
                        GenerateModuleSubmodulesJob::dispatch($this->record);
                        Notification::make()->title('Submódulos sendo criados...')->success()->send();
                    } else {
                        GenerateModuleTasksJob::dispatch($this->record);
                        Notification::make()->title('Tasks sendo criadas...')->success()->send();
                    }
                })
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && (
                        ($this->record->prd_payload['needs_submodules'] ?? false)
                            ? !$this->record->children()->exists()
                            : !$this->record->tasks()->exists()
                    )
                ),
        ];
    }
}
