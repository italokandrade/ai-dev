<?php

use App\Enums\ModuleStatus;
use App\Enums\Priority;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;

function createModuleProject(): Project
{
    return Project::create([
        'name' => 'test-module-project',
        'status' => 'active',
    ]);
}

test('module can be created', function () {
    $project = createModuleProject();

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Authentication',
        'description' => 'User authentication module',
        'status' => 'planned',
        'priority' => Priority::High,
    ]);

    expect($module)->toBeInstanceOf(ProjectModule::class)
        ->and($module->status)->toBe(ModuleStatus::Planned)
        ->and($module->priority)->toBe(Priority::High);
});

test('module recalculates progress from tasks', function () {
    $project = createModuleProject();

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Test Module',
        'description' => 'Test',
        'status' => 'in_progress',
    ]);

    Task::create([
        'project_id' => $project->id,
        'module_id' => $module->id,
        'title' => 'Task 1',
        'prd_payload' => ['objective' => 'test'],
        'status' => 'completed',
        'priority' => Priority::Medium,
        'source' => 'manual',
    ]);

    Task::create([
        'project_id' => $project->id,
        'module_id' => $module->id,
        'title' => 'Task 2',
        'prd_payload' => ['objective' => 'test'],
        'status' => 'pending',
        'priority' => Priority::Medium,
        'source' => 'manual',
    ]);

    $module->recalculateProgress();
    $module->refresh();

    expect($module->progress_percentage)->toBe(50.0);
});

test('module validates status transitions', function () {
    $project = createModuleProject();

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Test',
        'description' => 'Test',
        'status' => 'planned',
    ]);

    $module->transitionTo(ModuleStatus::InProgress);
    expect($module->fresh()->status)->toBe(ModuleStatus::InProgress);

    expect(fn () => $module->transitionTo(ModuleStatus::Completed))
        ->toThrow(InvalidArgumentException::class);
});

test('module checks dependencies met', function () {
    $project = createModuleProject();

    $dependency = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Dependency',
        'description' => 'Required first',
        'status' => 'planned',
    ]);

    $module = ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Dependent',
        'description' => 'Needs dependency',
        'status' => 'planned',
        'dependencies' => [$dependency->id],
    ]);

    expect($module->dependenciesMet())->toBeFalse();

    $dependency->update(['status' => 'completed']);
    expect($module->dependenciesMet())->toBeTrue();
});
