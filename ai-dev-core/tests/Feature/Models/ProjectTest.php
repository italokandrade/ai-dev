<?php

namespace Tests\Feature\Models;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_can_be_created(): void
    {
        $project = Project::create([
            'name' => 'test-project',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
        ]);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('test-project', $project->name);
        $this->assertEquals('active', $project->status->value);
    }

    public function test_project_has_modules_relationship(): void
    {
        $project = Project::create([
            'name' => 'test-modules-rel',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
        ]);

        ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Auth Module',
            'description' => 'Authentication module',
            'status' => 'planned',
        ]);

        $this->assertCount(1, $project->modules);
        $this->assertEquals('Auth Module', $project->modules->first()->name);
    }

    public function test_project_has_specifications_relationship(): void
    {
        $project = Project::create([
            'name' => 'test-spec-rel',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
        ]);

        ProjectSpecification::create([
            'project_id' => $project->id,
            'user_description' => 'A test system',
            'version' => 1,
        ]);

        $this->assertCount(1, $project->specifications);
        $this->assertNotNull($project->currentSpecification);
    }

    public function test_project_calculates_overall_progress(): void
    {
        $project = Project::create([
            'name' => 'test-progress',
            'status' => 'active',
            'default_provider' => 'anthropic',
            'default_model' => 'claude-sonnet-4-6',
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

        $this->assertEquals(50.0, $project->overallProgress());
    }
}
