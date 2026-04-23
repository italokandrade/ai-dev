<?php

namespace App\Filament\Components;

use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use App\Models\ProjectModule;
use App\Models\Task;
use Illuminate\Support\HtmlString;

class NavigationTree
{
    /**
     * Gera o breadcrumb HTML para um módulo (Projeto > Pai > ... > Atual).
     */
    public static function forModule(ProjectModule $module): HtmlString
    {
        $segments = [];

        // Projeto (raiz)
        $projectUrl = ProjectResource::getUrl('view', ['record' => $module->project_id]);
        $segments[] = self::link($projectUrl, '📁 ' . e($module->project->name));

        // Ancestrais do módulo
        $ancestors = collect();
        $current = $module->parent;
        while ($current) {
            $ancestors->prepend($current);
            $current = $current->parent;
        }

        foreach ($ancestors as $ancestor) {
            $url = ProjectModuleResource::getUrl('view', ['record' => $ancestor->id]);
            $segments[] = self::link($url, e($ancestor->name));
        }

        // Módulo atual (ativo, sem link)
        $segments[] = self::active(e($module->name));

        return self::wrap($segments);
    }

    /**
     * Gera o breadcrumb HTML para uma task (Projeto > Módulo > Tarefa).
     */
    public static function forTask(Task $task): HtmlString
    {
        $segments = [];

        // Projeto
        $projectUrl = ProjectResource::getUrl('view', ['record' => $task->project_id]);
        $segments[] = self::link($projectUrl, '📁 ' . e($task->project->name));

        // Módulo (se houver)
        if ($task->module_id && $task->module) {
            $moduleUrl = ProjectModuleResource::getUrl('view', ['record' => $task->module_id]);
            $segments[] = self::link($moduleUrl, '🔲 ' . e($task->module->name));
        }

        // Task atual
        $segments[] = self::active('📋 ' . e($task->title));

        return self::wrap($segments);
    }

    /**
     * Gera o breadcrumb HTML para um projeto (lista de projetos > Projeto).
     */
    public static function forProject(\App\Models\Project $project): HtmlString
    {
        $segments = [];

        $indexUrl = ProjectResource::getUrl('index');
        $segments[] = self::link($indexUrl, '📁 Projetos');
        $segments[] = self::active(e($project->name));

        return self::wrap($segments);
    }

    private static function link(string $url, string $label): string
    {
        return sprintf(
            '<a href="%s" class="text-primary-600 hover:text-primary-500 hover:underline transition font-medium">%s</a>',
            $url,
            $label
        );
    }

    private static function active(string $label): string
    {
        return sprintf(
            '<span class="text-gray-800 font-bold bg-gray-100 px-2 py-0.5 rounded-md">%s</span>',
            $label
        );
    }

    /**
     * @param array<string> $segments
     */
    private static function wrap(array $segments): HtmlString
    {
        $html = '<div class="flex items-center gap-2 text-sm text-gray-500 flex-wrap">'
            . implode(' <span class="text-gray-300">/</span> ', $segments)
            . '</div>';

        return new HtmlString($html);
    }
}
