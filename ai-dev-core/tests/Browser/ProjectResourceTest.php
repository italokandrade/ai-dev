<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ProjectResourceTest extends DuskTestCase
{
    public function test_projects_list_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/projects')
                ->waitForText('Projects')
                ->assertSee('Novo Projeto');
        });
    }

    public function test_create_project_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/projects/create')
                ->waitForText('Create Project')
                ->assertPresent('[wire\\:model]');
        });
    }
}
