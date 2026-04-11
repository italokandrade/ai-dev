<?php

namespace Tests\Feature\Models;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'test-task-project',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
        ]);
    }

    public function test_task_can_be_created_with_prd_payload(): void
    {
        $project = $this->createProject();

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
            'priority' => 70,
            'source' => 'manual',
        ]);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertEquals('Create login page', $task->prd_payload['objective']);
        $this->assertCount(2, $task->prd_payload['acceptance_criteria']);
    }

    public function test_task_valid_status_transition(): void
    {
        $project = $this->createProject();

        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Test transition',
            'prd_payload' => ['objective' => 'test'],
            'status' => 'pending',
            'priority' => 50,
            'source' => 'manual',
        ]);

        $task->transitionTo(TaskStatus::InProgress, 'test');
        $this->assertEquals(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_task_invalid_status_transition_throws(): void
    {
        $project = $this->createProject();

        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Test invalid transition',
            'prd_payload' => ['objective' => 'test'],
            'status' => 'pending',
            'priority' => 50,
            'source' => 'manual',
        ]);

        $task->transitionTo(TaskStatus::InProgress, 'test');

        $this->expectException(\InvalidArgumentException::class);
        $task->transitionTo(TaskStatus::Completed, 'test');
    }

    public function test_task_can_check_retry_availability(): void
    {
        $project = $this->createProject();

        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Test retry',
            'prd_payload' => ['objective' => 'test'],
            'status' => 'pending',
            'priority' => 50,
            'source' => 'manual',
            'retry_count' => 2,
            'max_retries' => 3,
        ]);

        $this->assertTrue($task->canRetry());

        $task->update(['retry_count' => 3]);
        $this->assertFalse($task->canRetry());
    }
}
