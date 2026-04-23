<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\ProjectModuleResource;
use App\Models\ProjectModule;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rootModules';

    protected static ?string $title = 'Módulos do Projeto';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Módulo')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-squares-2x2')
                    ->url(fn (ProjectModule $record): string => ProjectModuleResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progresso')
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->color(fn ($state) => match (true) {
                        $state >= 80 => 'success',
                        $state >= 40 => 'info',
                        $state > 0 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Submódulos')
                    ->counts('children')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
                    ->badge()
                    ->color('success'),
            ])
            ->defaultSort('created_at', 'asc')
            ->headerActions([
                Action::make('create_module')
                    ->label('Novo Módulo')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => ProjectModuleResource::getUrl('create', ['project_id' => $this->getOwnerRecord()->id]))
                    ->openUrlInNewTab(false),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ProjectModule $record): string => ProjectModuleResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false)
                    ->link(),
            ])
            ->bulkActions([]);
    }
}
