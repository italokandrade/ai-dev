<?php

namespace App\Policies;

use App\Models\AgentConfig;
use App\Models\User;

class AgentConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isDeveloper();
    }

    public function view(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isDeveloper();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AgentConfig $agentConfig): bool
    {
        return $user->isAdmin();
    }
}
