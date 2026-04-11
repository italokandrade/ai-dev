<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class TaskResourceTest extends DuskTestCase
{
    public function test_tasks_list_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/tasks')
                ->waitForText('Tasks')
                ->assertSee('Nova Task');
        });
    }

    public function test_create_task_page_loads(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'italokristiano@gmail.com'],
            ['name' => 'Italo Andrade', 'password' => bcrypt('password')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/admin/tasks/create')
                ->waitForText('Create Task')
                ->assertPresent('[wire\\:model]');
        });
    }
}
