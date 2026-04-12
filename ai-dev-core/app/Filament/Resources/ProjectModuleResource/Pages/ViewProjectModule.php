<?php

namespace App\Filament\Resources\ProjectModuleResource\Pages;

use App\Enums\ModuleStatus;
use App\Filament\Resources\ProjectModuleResource;
use App\Models\ProjectModule;
use Filament\Actions;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewProjectModule extends ViewRecord
{
    protected static string $resource = ProjectModuleResource::class;

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
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('parent.name')
                                    ->label('Módulo Pai')
                                    ->placeholder('Módulo Raiz'),

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
                                            ->icon('heroicon-o-document-text'),

                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Status')
                                            ->badge(),

                                        Infolists\Components\TextEntry::make('progress_percentage')
                                            ->label('Progresso')
                                            ->formatStateUsing(fn ($state) => $state . '%'),

                                        Infolists\Components\TextEntry::make('tasks_count')
                                            ->label('Tasks')
                                            ->getStateUsing(fn (ProjectModule $record) => $record->tasks()->count()),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->visible(fn () => $this->record->children()->exists()),
            ]);
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
        ];
    }
}
