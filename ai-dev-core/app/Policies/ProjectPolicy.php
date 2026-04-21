<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Todos logados podem ver a lista
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isDeveloper(); // Admin ou Dev podem criar projetos
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isDeveloper();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin(); // Apenas Admin pode deletar projetos
    }
}
