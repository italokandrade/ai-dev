<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\AgentConfig;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AgentHealthWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Status dos Agentes';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AgentConfig::query()
                    ->withCount([
                        'assignedTasks',
                        'assignedTasks as running_tasks_count' => fn ($q) => $q->where('status', TaskStatus::InProgress),
                        'assignedTasks as completed_tasks_count' => fn ($q) => $q->where('status', TaskStatus::Completed),
                        'assignedTasks as failed_tasks_count' => fn ($q) => $q->where('status', TaskStatus::Failed),
                    ])
                    ->orderBy('id')
            )
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Agente')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('model')
                    ->label('Modelo'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('running_tasks_count')
                    ->label('Executando')
                    ->color('info'),

                Tables\Columns\TextColumn::make('completed_tasks_count')
                    ->label('Concluidas')
                    ->color('success'),

                Tables\Columns\TextColumn::make('failed_tasks_count')
                    ->label('Falhas')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('success_rate')
                    ->label('Taxa Sucesso')
                    ->state(function (AgentConfig $record) {
                        $total = $record->completed_tasks_count + $record->failed_tasks_count;

                        return $total > 0
                            ? round(($record->completed_tasks_count / $total) * 100) . '%'
                            : '-';
                    })
                    ->color(function (AgentConfig $record) {
                        $total = $record->completed_tasks_count + $record->failed_tasks_count;
                        if ($total === 0) {
                            return 'gray';
                        }
                        $rate = ($record->completed_tasks_count / $total) * 100;

                        return $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
                    }),
            ])
            ->paginated(false);
    }
}
