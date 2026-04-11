<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AgentConfigResourceTest extends DuskTestCase
{
    public function test_agents_list_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs')
                ->waitForText('Novo Agente', 10)
                ->assertSee('Novo Agente');
        });
    }

    public function test_agents_list_shows_seeded_agents(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs')
                ->waitForText('orchestrator', 10)
                ->assertSee('orchestrator')
                ->assertSee('backend-specialist');
        });
    }

    public function test_create_agent_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/agent-configs/create')
                ->waitForText('Identificacao', 10)
                ->assertSee('Identificacao');
        });
    }
}
