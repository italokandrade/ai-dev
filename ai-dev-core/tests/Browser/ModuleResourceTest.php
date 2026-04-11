<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ModuleResourceTest extends DuskTestCase
{
    public function test_modules_list_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/project-modules')
                ->waitForText('Modulos')
                ->assertSee('Novo Modulo');
        });
    }

    public function test_create_module_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/project-modules/create')
                ->waitForText('Create Modulo')
                ->assertPresent('[wire\\:model]');
        });
    }
}
