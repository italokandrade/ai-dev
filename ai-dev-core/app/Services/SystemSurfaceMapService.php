<?php

namespace App\Services;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

class SystemSurfaceMapService
{
    private const array SUBJECT_TYPE_ALIASES = [
        'security.roles' => [
            SpatieRole::class,
            'App\Models\Role',
        ],
        'security.permissions' => [
            SpatiePermission::class,
            'App\Models\Permission',
        ],
    ];

    /**
     * Labels canonicos para os assuntos que podem aparecer no activity_log.
     * O scan de App\Models garante que novos Models aparecam automaticamente
     * nos filtros de auditoria antes mesmo do primeiro evento registrado.
     *
     * @param  array<int, string>  $extraSubjectTypes
     * @return array<string, string>
     */
    public static function activitySubjectLabels(array $extraSubjectTypes = []): array
    {
        $labels = [
            SpatieRole::class => 'Perfil de Usuário',
            SpatiePermission::class => 'Permissão',
        ];

        foreach (self::modelClasses() as $modelClass) {
            if (self::isAliasedSubjectType($modelClass)) {
                continue;
            }

            $labels[$modelClass] = self::modelLabel($modelClass);
        }

        foreach ($extraSubjectTypes as $subjectType) {
            if (is_string($subjectType) && $subjectType !== '') {
                $labels[$subjectType] ??= self::modelLabel($subjectType);
            }
        }

        asort($labels);

        return $labels;
    }

    /**
     * Opções canonicas para filtros de activity_log.
     * Classes equivalentes compartilham uma unica opção visual.
     *
     * @param  array<int, string>  $extraSubjectTypes
     * @return array<string, string>
     */
    public static function activitySubjectFilterOptions(array $extraSubjectTypes = []): array
    {
        $labels = [];

        foreach (self::SUBJECT_TYPE_ALIASES as $filterKey => $classes) {
            $labels[$filterKey] = self::modelLabel($classes[0]);
        }

        foreach (array_keys(self::activitySubjectLabels($extraSubjectTypes)) as $subjectType) {
            $labels[self::activitySubjectFilterKey($subjectType)] = self::modelLabel($subjectType);
        }

        foreach ($extraSubjectTypes as $subjectType) {
            if (is_string($subjectType) && $subjectType !== '') {
                $labels[self::activitySubjectFilterKey($subjectType)] = self::modelLabel($subjectType);
            }
        }

        asort($labels);

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    public static function subjectTypesForFilter(string $filterValue): array
    {
        return self::SUBJECT_TYPE_ALIASES[$filterValue] ?? [$filterValue];
    }

    public static function activitySubjectFilterKey(string $subjectType): string
    {
        foreach (self::SUBJECT_TYPE_ALIASES as $filterKey => $classes) {
            if (in_array($subjectType, $classes, true)) {
                return $filterKey;
            }
        }

        return $subjectType;
    }

    public static function modelLabel(?string $fqcn): string
    {
        if (! $fqcn) {
            return '—';
        }

        return match ($fqcn) {
            'App\Models\AgentConfig' => 'Agente',
            'App\Models\Permission', SpatiePermission::class => 'Permissão',
            'App\Models\Project' => 'Projeto',
            'App\Models\ProjectFeature' => 'Funcionalidade',
            'App\Models\ProjectModule' => 'Módulo',
            'App\Models\ProjectQuotation' => 'Orçamento',
            'App\Models\ProjectSpecification' => 'Especificação',
            'App\Models\Role', SpatieRole::class => 'Perfil de Usuário',
            'App\Models\SocialAccount' => 'Conta Social',
            'App\Models\Subtask' => 'Subtarefa',
            'App\Models\SystemSetting' => 'Config. Sistema',
            'App\Models\Task' => 'Tarefa',
            'App\Models\TaskTransition' => 'Transição de Status',
            'App\Models\ToolCallLog' => 'Chamada de Ferramenta',
            'App\Models\User' => 'Usuário',
            default => Str::headline(class_basename($fqcn)),
        };
    }

    private static function isAliasedSubjectType(string $subjectType): bool
    {
        foreach (self::SUBJECT_TYPE_ALIASES as $classes) {
            if (in_array($subjectType, $classes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mapa vivo das superficies Filament do painel. Resources, Pages e Widgets
     * entram aqui automaticamente quando forem descobertos pelo PanelProvider.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function filamentSurfaces(string $panelId = 'admin'): array
    {
        try {
            $panel = Filament::getPanel($panelId);

            return [
                'resources' => collect($panel->getResources())
                    ->map(fn (string $resource): array => [
                        'class' => $resource,
                        'label' => self::safeStaticCall($resource, 'getNavigationLabel') ?? class_basename($resource),
                        'group' => self::safeStaticCall($resource, 'getNavigationGroup'),
                        'slug' => self::safeStaticCall($resource, 'getSlug'),
                        'model' => self::safeStaticCall($resource, 'getModel'),
                    ])
                    ->sortBy('label')
                    ->values()
                    ->all(),

                'pages' => collect($panel->getPages())
                    ->map(fn (string $page): array => [
                        'class' => $page,
                        'label' => self::safeStaticCall($page, 'getNavigationLabel') ?? class_basename($page),
                        'group' => self::safeStaticCall($page, 'getNavigationGroup'),
                    ])
                    ->sortBy('label')
                    ->values()
                    ->all(),

                'widgets' => collect($panel->getWidgets())
                    ->map(function (mixed $widget): array {
                        $widgetClass = is_string($widget)
                            ? $widget
                            : (method_exists($widget, 'getWidget') ? $widget->getWidget() : $widget::class);

                        return [
                            'class' => $widgetClass,
                            'label' => Str::headline(class_basename($widgetClass)),
                            'permission' => 'View:'.class_basename($widgetClass),
                        ];
                    })
                    ->sortBy('label')
                    ->values()
                    ->all(),
            ];
        } catch (\Throwable) {
            return [
                'resources' => [],
                'pages' => [],
                'widgets' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function fullMap(string $panelId = 'admin'): array
    {
        return [
            'models' => self::activitySubjectLabels(),
            'filament' => self::filamentSurfaces($panelId),
            'admin_routes' => self::adminRoutes(),
        ];
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public static function modelClasses(): array
    {
        $modelsPath = app_path('Models');

        if (! is_dir($modelsPath)) {
            return [];
        }

        return collect(File::allFiles($modelsPath))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(function ($file): ?string {
                $relative = Str::of($file->getPathname())
                    ->after(app_path().DIRECTORY_SEPARATOR)
                    ->replace(DIRECTORY_SEPARATOR, '\\')
                    ->replaceLast('.php', '');

                $class = 'App\\'.$relative;

                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    return null;
                }

                $reflection = new ReflectionClass($class);

                return $reflection->isAbstract() ? null : $class;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function adminRoutes(): array
    {
        try {
            return collect(Route::getRoutes())
                ->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin'))
                ->map(fn ($route): array => [
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'methods' => $route->methods(),
                ])
                ->sortBy('uri')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function safeStaticCall(string $class, string $method): mixed
    {
        try {
            return method_exists($class, $method) ? $class::{$method}() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
