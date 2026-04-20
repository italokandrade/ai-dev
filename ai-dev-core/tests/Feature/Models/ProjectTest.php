<?php

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;

test('project can be created', function () {
    $project = Project::create([
        'name' => 'test-project',
        'status' => 'active',
    ]);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->name)->toBe('test-project')
        ->and($project->status->value)->toBe('active');
});

test('project has modules relationship', function () {
    $project = Project::create([
        'name' => 'test-modules-rel',
        'status' => 'active',
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Auth Module',
        'description' => 'Authentication module',
        'status' => 'planned',
    ]);

    expect($project->modules)->toHaveCount(1)
        ->and($project->modules->first()->name)->toBe('Auth Module');
});

test('project has specifications relationship', function () {
    $project = Project::create([
        'name' => 'test-spec-rel',
        'status' => 'active',
    ]);

    ProjectSpecification::create([
        'project_id' => $project->id,
        'user_description' => 'A test system',
        'version' => 1,
    ]);

    expect($project->specifications)->toHaveCount(1)
        ->and($project->currentSpecification)->not->toBeNull();
});

test('project calculates overall progress', function () {
    $project = Project::create([
        'name' => 'test-progress',
        'status' => 'active',
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Module A',
        'description' => 'First',
        'status' => 'completed',
        'progress_percentage' => 100,
    ]);

    ProjectModule::create([
        'project_id' => $project->id,
        'name' => 'Module B',
        'description' => 'Second',
        'status' => 'planned',
        'progress_percentage' => 0,
    ]);

    expect($project->overallProgress())->toBe(50.0);
});
