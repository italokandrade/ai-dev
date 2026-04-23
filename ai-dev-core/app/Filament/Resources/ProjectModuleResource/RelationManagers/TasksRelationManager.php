<?php

namespace App\Filament\Resources\ProjectModuleResource\RelationManagers;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks do Módulo';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->weight('bold')
                    ->searchable()
                    ->limit(70)
                    ->url(fn (Task $record): string => route('filament.admin.resources.tasks.view', $record))
                    ->openUrlInNewTab(false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('assignedAgent.display_name')
                    ->label('Agente')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Retries')
                    ->formatStateUsing(fn (Task $record) => "{$record->retry_count}/{$record->max_retries}")
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TaskStatus::class)
                    ->multiple(),
            ])
            ->actions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Task $record) => route('filament.admin.resources.tasks.view', $record))
                    ->link(),
            ])
            ->headerActions([
                Action::make('create_task')
                    ->label('Nova Task')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => route('filament.admin.resources.tasks.create', [
                        'project_id' => $this->getOwnerRecord()->project_id,
                        'module_id'  => $this->getOwnerRecord()->id,
                    ])),
            ])
            ->bulkActions([]);
    }
}
