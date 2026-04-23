<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('view_any_task');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('view_task');
    }

    public function create(User $user): bool
    {
        return $user->isDeveloper() || $user->can('create_task');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->isDeveloper() || $user->can('update_task');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('delete_task');
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('delete_any_task');
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('restore_task');
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('restore_any_task');
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->isAdmin() || $user->can('force_delete_task');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('force_delete_any_task');
    }

    public function replicate(User $user, Task $task): bool
    {
        return $user->isDeveloper() || $user->can('replicate_task');
    }

    public function reorder(User $user): bool
    {
        return $user->isDeveloper() || $user->can('reorder_task');
    }
}
