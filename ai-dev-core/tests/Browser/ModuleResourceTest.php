<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ModuleResourceTest extends DuskTestCase
{
    public function test_modules_list_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/project-modules')
                ->waitForText('Novo Modulo', 10)
                ->assertSee('Novo Modulo');
        });
    }

    public function test_create_module_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/project-modules/create')
                ->waitForText('Dados do Modulo', 10)
                ->assertSee('Dados do Modulo');
        });
    }
}
