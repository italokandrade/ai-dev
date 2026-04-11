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
                ->assertSee('Login')
                ->assertPresent('input[type="email"]')
                ->assertPresent('input[type="password"]');
        });
    }

    public function test_user_can_login(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/admin/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->press('Sign in')
                ->waitForLocation('/admin')
                ->assertPathIs('/admin');
        });
    }

    public function test_invalid_login_shows_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->type('input[type="email"]', 'wrong@email.com')
                ->type('input[type="password"]', 'wrongpassword')
                ->press('Sign in')
                ->waitForText('These credentials do not match')
                ->assertSee('These credentials do not match');
        });
    }
}
