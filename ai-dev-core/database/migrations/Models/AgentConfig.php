<?php

namespace App\Models;

use App\Enums\AgentProvider;
use App\Enums\KnowledgeArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentConfig extends Model
{
    protected $table = 'agents_config';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'display_name',
        'role_description',
        'provider',
        'model',
        'api_key_env_var',
        'temperature',
        'max_tokens',
        'knowledge_areas',
        'max_parallel_tasks',
        'is_active',
        'fallback_agent_id',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AgentProvider::class,
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'knowledge_areas' => 'array',
            'max_parallel_tasks' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function fallbackAgent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_agent_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_agent_id');
    }

    public function getApiKey(): ?string
    {
        return env($this->api_key_env_var);
    }
}
