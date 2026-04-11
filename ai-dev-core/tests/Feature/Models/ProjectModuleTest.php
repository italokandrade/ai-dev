<?php

namespace Tests\Feature\Models;

use App\Enums\ModuleStatus;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectModuleTest extends TestCase
{
    use RefreshDatabase;

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'test-module-project',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
        ]);
    }

    public function test_module_can_be_created(): void
    {
        $project = $this->createProject();

        $module = ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Authentication',
            'description' => 'User authentication module',
            'status' => 'planned',
            'priority' => 90,
            'order' => 1,
        ]);

        $this->assertInstanceOf(ProjectModule::class, $module);
        $this->assertEquals(ModuleStatus::Planned, $module->status);
        $this->assertEquals(90, $module->priority);
    }

    public function test_module_recalculates_progress_from_tasks(): void
    {
        $project = $this->createProject();

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
            'priority' => 50,
            'source' => 'manual',
        ]);

        Task::create([
            'project_id' => $project->id,
            'module_id' => $module->id,
            'title' => 'Task 2',
            'prd_payload' => ['objective' => 'test'],
            'status' => 'pending',
            'priority' => 50,
            'source' => 'manual',
        ]);

        $module->recalculateProgress();
        $module->refresh();

        $this->assertEquals(50.0, $module->progress_percentage);
    }

    public function test_module_validates_status_transitions(): void
    {
        $project = $this->createProject();

        $module = ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Test',
            'description' => 'Test',
            'status' => 'planned',
        ]);

        $module->transitionTo(ModuleStatus::InProgress);
        $this->assertEquals(ModuleStatus::InProgress, $module->fresh()->status);

        $this->expectException(\InvalidArgumentException::class);
        $module->transitionTo(ModuleStatus::Completed);
    }

    public function test_module_checks_dependencies_met(): void
    {
        $project = $this->createProject();

        $dep = ProjectModule::create([
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
            'dependencies' => [$dep->id],
        ]);

        $this->assertFalse($module->dependenciesMet());

        $dep->update(['status' => 'completed']);
        $this->assertTrue($module->dependenciesMet());
    }
}
