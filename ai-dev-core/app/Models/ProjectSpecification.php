<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSpecification extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'user_description',
        'ai_specification',
        'version',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'ai_specification' => 'array',
            'version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function approve(User $user): void
    {
        $this->update([
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);
    }
}
