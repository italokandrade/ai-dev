<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DashboardTest extends DuskTestCase
{
    public function test_dashboard_loads_with_widgets(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin')
                ->waitForText('Projetos')
                ->assertSee('Projetos')
                ->assertSee('Modulos')
                ->assertSee('Tasks')
                ->assertSee('Agentes Ativos');
        });
    }

    public function test_dashboard_shows_roadmap_widget(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin')
                ->waitForText('Roadmap dos Projetos')
                ->assertSee('Roadmap dos Projetos')
                ->assertSee('Tasks Recentes')
                ->assertSee('Status dos Agentes');
        });
    }
}
