<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Autonomous Planning Guardrails
    |--------------------------------------------------------------------------
    |
    | These values protect the autonomous cascade from runaway generation while
    | still allowing large systems to be planned. Set a value to 0 to remove
    | that specific ceiling.
    |
    */
    'planning' => [
        'max_root_modules_per_project' => env('AI_DEV_MAX_ROOT_MODULES_PER_PROJECT', 200),
        'max_modules_per_project' => env('AI_DEV_MAX_MODULES_PER_PROJECT', 1000),
        'max_submodule_depth' => env('AI_DEV_MAX_SUBMODULE_DEPTH', 3),
        'max_submodules_per_module' => env('AI_DEV_MAX_SUBMODULES_PER_MODULE', 30),
        'max_tasks_per_module' => env('AI_DEV_MAX_TASKS_PER_MODULE', 30),

        'max_blueprint_entities' => env('AI_DEV_MAX_BLUEPRINT_ENTITIES', 1000),
        'max_blueprint_columns_per_entity' => env('AI_DEV_MAX_BLUEPRINT_COLUMNS_PER_ENTITY', 300),
        'max_blueprint_relationships' => env('AI_DEV_MAX_BLUEPRINT_RELATIONSHIPS', 3000),
        'max_blueprint_artifacts_per_group' => env('AI_DEV_MAX_BLUEPRINT_ARTIFACTS_PER_GROUP', 1000),
    ],
];
