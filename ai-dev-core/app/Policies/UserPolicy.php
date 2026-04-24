<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ViewAny:User');
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->can('View:User');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->can('Create:User');
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->can('Update:User');
    }

    public function delete(User $user, User $model): bool
    {
        return ($user->isAdmin() || $user->can('Delete:User')) && $user->id !== $model->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('DeleteAny:User');
    }
}
