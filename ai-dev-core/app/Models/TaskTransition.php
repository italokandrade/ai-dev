<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskTransition extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'from_status',
        'to_status',
        'triggered_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function entity(): Model
    {
        return match ($this->entity_type) {
            'task' => Task::find($this->entity_id),
            'subtask' => Subtask::find($this->entity_id),
        };
    }
}
