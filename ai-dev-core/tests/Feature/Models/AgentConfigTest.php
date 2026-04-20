<?php

use App\Enums\AgentProvider;
use App\Models\AgentConfig;

beforeEach(function () {
    $this->seed(\Database\Seeders\AgentsConfigSeeder::class);
});

test('agent configs are seeded', function () {
    expect(AgentConfig::count())->toBeGreaterThanOrEqual(10);
});

test('orchestrator agent exists and is anthropic', function () {
    $orchestrator = AgentConfig::find('orchestrator');

    expect($orchestrator)->not->toBeNull()
        ->and($orchestrator->provider)->toBe(AgentProvider::Anthropic)
        ->and($orchestrator->is_active)->toBeTrue();
});

test('backend specialist is gemini', function () {
    $backend = AgentConfig::find('backend-specialist');

    expect($backend)->not->toBeNull()
        ->and($backend->provider)->toBe(AgentProvider::Gemini);
});

test('agent has assigned tasks relationship', function () {
    $agent = AgentConfig::find('orchestrator');

    expect($agent->assignedTasks)->toHaveCount(0);
});
