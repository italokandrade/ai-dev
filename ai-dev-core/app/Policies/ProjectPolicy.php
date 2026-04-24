<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ViewAny:Project');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->can('View:Project');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->can('Create:Project');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->can('Update:Project');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->can('Delete:Project');
    }
}
