<?php

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;

function createProject(): Project
{
    return Project::create([
        'name' => 'test-task-project',
        'status' => 'active',
    ]);
}

test('task can be created with prd payload', function () {
    $project = createProject();

    $task = Task::create([
        'project_id' => $project->id,
        'title' => 'Implement login',
        'prd_payload' => [
            'objective' => 'Create login page',
            'acceptance_criteria' => ['User can log in', 'Error shown on failure'],
            'constraints' => ['Use Filament auth'],
            'knowledge_areas' => ['backend', 'filament'],
        ],
        'status' => 'pending',
        'priority' => Priority::High,
        'source' => 'manual',
    ]);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->prd_payload['objective'])->toBe('Create login page')
        ->and($task->prd_payload['acceptance_criteria'])->toHaveCount(2);
});

test('task valid status transition', function () {
    $project = createProject();

    $task = Task::create([
        'project_id' => $project->id,
        'title' => 'Test transition',
        'prd_payload' => ['objective' => 'test'],
        'status' => 'pending',
        'priority' => Priority::Medium,
        'source' => 'manual',
    ]);

    $task->transitionTo(TaskStatus::InProgress, 'test');

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
});

test('task invalid status transition throws', function () {
    $project = createProject();

    $task = Task::create([
        'project_id' => $project->id,
        'title' => 'Test invalid transition',
        'prd_payload' => ['objective' => 'test'],
        'status' => 'pending',
        'priority' => Priority::Medium,
        'source' => 'manual',
    ]);

    $task->transitionTo(TaskStatus::InProgress, 'test');

    expect(fn () => $task->transitionTo(TaskStatus::Completed, 'test'))
        ->toThrow(InvalidArgumentException::class);
});

test('task can check retry availability', function () {
    $project = createProject();

    $task = Task::create([
        'project_id' => $project->id,
        'title' => 'Test retry',
        'prd_payload' => ['objective' => 'test'],
        'status' => 'pending',
        'priority' => Priority::Medium,
        'source' => 'manual',
        'retry_count' => 2,
        'max_retries' => 3,
    ]);

    expect($task->canRetry())->toBeTrue();

    $task->update(['retry_count' => 3]);
    expect($task->canRetry())->toBeFalse();
});
