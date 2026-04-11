<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DashboardTest extends DuskTestCase
{
    public function test_dashboard_loads_with_stats(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin')
                ->waitForText('Projetos', 10)
                ->assertSee('Projetos')
                ->assertSee('Tasks');
        });
    }
}
