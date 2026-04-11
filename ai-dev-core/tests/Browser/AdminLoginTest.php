<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminLoginTest extends DuskTestCase
{
    public function test_login_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->assertSee('Sign in')
                ->assertPresent('input[type="email"]')
                ->assertPresent('input[type="password"]');
        });
    }

    public function test_user_can_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->type('input[type="email"]', 'italokristiano@gmail.com')
                ->type('input[type="password"]', 'Italo2000')
                ->press('Sign in')
                ->waitForLocation('/admin', 10)
                ->assertPathIs('/admin');
        });
    }
}
