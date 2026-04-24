<?php

use App\Enums\Priority;
use App\Enums\SubtaskStatus;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;

test('subtask file locks include running and qa audit subtasks', function () {
    $project = Project::create([
        'name' => 'lock-project',
        'status' => 'active',
    ]);

    $task = Task::create([
        'project_id' => $project->id,
        'title' => 'Task with locks',
        'prd_payload' => ['objective' => 'test locks'],
        'status' => 'pending',
        'priority' => Priority::Medium,
        'source' => 'manual',
    ]);

    Subtask::create([
        'task_id' => $task->id,
        'title' => 'Running subtask',
        'sub_prd_payload' => ['objective' => 'running'],
        'status' => SubtaskStatus::Running,
        'assigned_agent' => 'backend-specialist',
        'file_locks' => ['app/Models/User.php'],
    ]);

    $pending = Subtask::create([
        'task_id' => $task->id,
        'title' => 'Pending subtask',
        'sub_prd_payload' => ['objective' => 'pending'],
        'status' => SubtaskStatus::Pending,
        'assigned_agent' => 'backend-specialist',
        'file_locks' => ['app/Models/User.php'],
    ]);

    expect($pending->hasFileLockConflict())->toBeTrue();

    Subtask::query()->where('title', 'Running subtask')->update([
        'status' => SubtaskStatus::QaAudit,
    ]);

    expect($pending->fresh()->hasFileLockConflict())->toBeTrue();
});
