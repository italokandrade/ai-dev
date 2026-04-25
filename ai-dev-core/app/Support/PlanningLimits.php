<?php

namespace App\Support;

final class PlanningLimits
{
    public static function rootModulesPerProject(): ?int
    {
        return self::positiveInt('max_root_modules_per_project', 40);
    }

    public static function modulesPerProject(): ?int
    {
        return self::positiveInt('max_modules_per_project', 250);
    }

    public static function submoduleDepth(): ?int
    {
        return self::positiveInt('max_submodule_depth', 2);
    }

    public static function submodulesPerModule(): ?int
    {
        return self::positiveInt('max_submodules_per_module', 8);
    }

    public static function tasksPerModule(): ?int
    {
        return self::positiveInt('max_tasks_per_module', 30);
    }

    public static function blueprintEntities(): ?int
    {
        return self::positiveInt('max_blueprint_entities', 250);
    }

    public static function blueprintColumnsPerEntity(): ?int
    {
        return self::positiveInt('max_blueprint_columns_per_entity', 120);
    }

    public static function blueprintRelationships(): ?int
    {
        return self::positiveInt('max_blueprint_relationships', 800);
    }

    public static function blueprintArtifactsPerGroup(): ?int
    {
        return self::positiveInt('max_blueprint_artifacts_per_group', 200);
    }

    public static function deferTaskGenerationUntilProjectPrdsComplete(): bool
    {
        return (bool) config('ai_dev.planning.defer_task_generation_until_project_prds_complete', true);
    }

    private static function positiveInt(string $key, int $default): ?int
    {
        $value = config("ai_dev.planning.{$key}", $default);

        if ($value === null || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
