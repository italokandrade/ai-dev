<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ProjectRoadmapWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Roadmap dos Projetos';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Project::query()
                    ->withCount(['modules', 'tasks'])
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Projeto')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                Tables\Columns\TextColumn::make('modules_count')
                    ->label('Modulos')
                    ->sortable(),

                Tables\Columns\TextColumn::make('overall_progress')
                    ->label('Progresso')
                    ->state(fn (Project $record) => $record->overallProgress() . '%')
                    ->color(fn (Project $record) => match (true) {
                        $record->overallProgress() >= 80 => 'success',
                        $record->overallProgress() >= 40 => 'info',
                        $record->overallProgress() > 0 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks'),

                Tables\Columns\TextColumn::make('default_provider')
                    ->label('Provider')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Project $record) => route('filament.admin.resources.projects.view', $record)),
            ])
            ->paginated([5]);
    }
}
