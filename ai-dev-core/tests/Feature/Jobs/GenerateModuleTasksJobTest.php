<?php

use App\Jobs\GenerateModuleTasksJob;
use App\Models\Project;
use App\Services\StandardProjectModuleService;

test('generate module tasks skips standard ai dev modules', function () {
    $project = Project::create([
        'name' => 'standard-module-task-skip',
        'status' => 'active',
    ]);

    app(StandardProjectModuleService::class)->syncProject($project);

    $module = $project->modules()->where('name', 'Chatbox')->firstOrFail();
    $module->forceFill([
        'prd_payload' => array_merge($module->prd_payload, [
            'components' => [
                [
                    'type' => 'Widget',
                    'name' => 'DashboardChat',
                    'description' => 'Componente padrão já entregue pelo AI-Dev Core.',
                ],
            ],
        ]),
    ])->save();

    (new GenerateModuleTasksJob($module))->handle();

    expect($module->tasks()->count())->toBe(0);
});
