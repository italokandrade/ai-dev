<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ProjectResourceTest extends DuskTestCase
{
    public function test_projects_list_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/projects')
                ->waitForText('Novo Projeto', 10)
                ->assertSee('Novo Projeto');
        });
    }

    public function test_create_project_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/projects/create')
                ->waitForText('Nome do Projeto', 10)
                ->assertSee('Nome do Projeto');
        });
    }
}
