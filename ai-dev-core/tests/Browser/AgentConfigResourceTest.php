<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AgentConfigResourceTest extends DuskTestCase
{
    public function test_agents_list_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs')
                ->waitForText('Agentes')
                ->assertSee('Novo Agente');
        });
    }

    public function test_agents_list_shows_seeded_agents(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs')
                ->waitForText('orchestrator')
                ->assertSee('orchestrator')
                ->assertSee('backend-specialist')
                ->assertSee('qa-auditor');
        });
    }

    public function test_create_agent_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs/create')
                ->waitForText('Create Agente')
                ->assertPresent('[wire\\:model]');
        });
    }
}
