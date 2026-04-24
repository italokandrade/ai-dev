<?php

namespace App\Policies;

use App\Models\ProjectModule;
use App\Models\User;

class ProjectModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ViewAny:ProjectModule');
    }

    public function view(User $user, ProjectModule $module): bool
    {
        return $user->isAdmin() || $user->can('View:ProjectModule');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->can('Create:ProjectModule');
    }

    public function update(User $user, ProjectModule $module): bool
    {
        return $user->isAdmin() || $user->can('Update:ProjectModule');
    }

    public function delete(User $user, ProjectModule $module): bool
    {
        return $user->isAdmin() || $user->can('Delete:ProjectModule');
    }
}
