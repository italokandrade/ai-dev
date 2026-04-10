<?php

namespace App\Models;

use App\Enums\AgentProvider;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'github_repo',
        'local_path',
        'gemini_session_id',
        'claude_session_id',
        'default_provider',
        'default_model',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'default_provider' => AgentProvider::class,
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activeTasks(): HasMany
    {
        return $this->tasks()->whereNotIn('status', ['completed', 'failed']);
    }

    public function isActive(): bool
    {
        return $this->status === ProjectStatus::Active;
    }
}
