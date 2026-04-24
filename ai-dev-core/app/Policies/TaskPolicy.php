<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ViewAny:Task');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('View:Task');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->can('Create:Task');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('Update:Task');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('Delete:Task');
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('DeleteAny:Task');
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('Restore:Task');
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('RestoreAny:Task');
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('ForceDelete:Task');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ForceDeleteAny:Task');
    }

    public function replicate(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('Replicate:Task');
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin() || $user->can('Reorder:Task');
    }
}
