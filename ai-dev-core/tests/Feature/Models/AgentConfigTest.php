<?php

namespace Tests\Feature\Models;

use App\Enums\AgentProvider;
use App\Models\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AgentsConfigSeeder::class);
    }

    public function test_agent_configs_are_seeded(): void
    {
        $agents = AgentConfig::all();
        $this->assertGreaterThanOrEqual(10, $agents->count());
    }

    public function test_orchestrator_agent_exists_and_is_anthropic(): void
    {
        $orchestrator = AgentConfig::find('orchestrator');

        $this->assertNotNull($orchestrator);
        $this->assertEquals(AgentProvider::Anthropic, $orchestrator->provider);
        $this->assertTrue($orchestrator->is_active);
    }

    public function test_backend_specialist_is_gemini(): void
    {
        $backend = AgentConfig::find('backend-specialist');

        $this->assertNotNull($backend);
        $this->assertEquals(AgentProvider::Gemini, $backend->provider);
    }

    public function test_agent_has_assigned_tasks_relationship(): void
    {
        $agent = AgentConfig::find('orchestrator');
        $this->assertCount(0, $agent->assignedTasks);
    }
}
