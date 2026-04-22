<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TaskPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Task');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('View:Task');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Task');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can('Update:Task');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('Delete:Task');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Task');
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->can('Restore:Task');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Task');
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->can('ForceDelete:Task');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:Task');
    }

    public function replicate(User $user, Task $task): bool
    {
        return $user->can('Replicate:Task');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Task');
    }
}
