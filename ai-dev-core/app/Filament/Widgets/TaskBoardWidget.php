<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TaskBoardWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Tasks Recentes';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Task::query()
                    ->with(['project', 'module'])
                    ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed])
                    ->orderByDesc('priority')
                    ->orderByDesc('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projeto')
                    ->sortable(),

                Tables\Columns\TextColumn::make('module.name')
                    ->label('Modulo')
                    ->placeholder('Avulsa'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->limit(50)
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->sortable()
                    ->color(fn ($state) => match (true) {
                        $state >= 80 => 'danger',
                        $state >= 50 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('assignedAgent.display_name')
                    ->label('Agente')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criada')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Task $record) => route('filament.admin.resources.tasks.view', $record)),
            ])
            ->paginated([10]);
    }
}
