<?php

namespace App\Filament\Widgets;

use App\Enums\ModuleStatus;
use App\Enums\TaskStatus;
use App\Models\AgentConfig;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalProjects = Project::count();
        $totalModules = ProjectModule::count();
        $totalTasks = Task::count();
        $activeAgents = AgentConfig::where('is_active', true)->count();

        $completedTasks = Task::where('status', TaskStatus::Completed)->count();
        $pendingTasks = Task::where('status', TaskStatus::Pending)->count();
        $inProgressTasks = Task::where('status', TaskStatus::InProgress)->count();
        $failedTasks = Task::where('status', TaskStatus::Failed)->count();

        $completedModules = ProjectModule::where('status', ModuleStatus::Completed)->count();

        $completionRate = $totalTasks > 0
            ? round(($completedTasks / $totalTasks) * 100, 1)
            : 0;

        return [
            Stat::make('Projetos', $totalProjects)
                ->description('Total de projetos cadastrados')
                ->icon('heroicon-o-folder')
                ->color('primary'),

            Stat::make('Modulos', "{$completedModules}/{$totalModules}")
                ->description('Concluidos / Total')
                ->icon('heroicon-o-squares-2x2')
                ->color($completedModules === $totalModules && $totalModules > 0 ? 'success' : 'info'),

            Stat::make('Tasks', $totalTasks)
                ->description("{$pendingTasks} pendentes | {$inProgressTasks} em progresso | {$failedTasks} falhas")
                ->icon('heroicon-o-clipboard-document-list')
                ->color($failedTasks > 0 ? 'danger' : 'info'),

            Stat::make('Taxa de Conclusao', "{$completionRate}%")
                ->description("{$completedTasks} tasks concluidas")
                ->icon('heroicon-o-chart-bar')
                ->color($completionRate >= 80 ? 'success' : ($completionRate >= 50 ? 'warning' : 'gray')),

            Stat::make('Agentes Ativos', $activeAgents)
                ->description('Configurados e prontos')
                ->icon('heroicon-o-cpu-chip')
                ->color('success'),
        ];
    }
}
