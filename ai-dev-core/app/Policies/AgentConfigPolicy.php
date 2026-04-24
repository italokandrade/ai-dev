<?php

namespace App\Policies;

use App\Models\AgentConfig;
use App\Models\User;

class AgentConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('ViewAny:AgentConfig');
    }

    public function view(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isAdmin() || $user->can('View:AgentConfig');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->can('Create:AgentConfig');
    }

    public function update(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isAdmin() || $user->can('Update:AgentConfig');
    }

    public function delete(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isAdmin() || $user->can('Delete:AgentConfig');
    }
}
