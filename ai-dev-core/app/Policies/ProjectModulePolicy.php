<?php

namespace App\Policies;

use App\Models\ProjectModule;
use App\Models\User;

class ProjectModulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProjectModule $module): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isDeveloper();
    }

    public function update(User $user, ProjectModule $module): bool
    {
        return $user->isDeveloper();
    }

    public function delete(User $user, ProjectModule $module): bool
    {
        return $user->isAdmin();
    }
}
