<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class TaskResourceTest extends DuskTestCase
{
    public function test_tasks_list_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/tasks')
                ->waitForText('Nova Task', 10)
                ->assertSee('Nova Task');
        });
    }

    public function test_create_task_page_loads(): void
    {
        $user = User::where('email', 'italokristiano@gmail.com')->first();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/tasks/create')
                ->waitForText('Definicao da Task', 10)
                ->assertSee('Definicao da Task');
        });
    }
}
