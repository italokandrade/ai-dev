<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SocialAccount extends Model
{
    use HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['last_posted_at'])
            ->setDescriptionForEvent(fn (string $eventName) => "Conta social {$eventName}");
    }

    protected $fillable = [
        'project_id',
        'platform',
        'account_name',
        'credentials',
        'is_active',
        'token_expires_at',
        'last_posted_at',
    ];

    protected $casts = [
        'credentials'      => 'encrypted:array',
        'is_active'        => 'boolean',
        'token_expires_at' => 'datetime',
        'last_posted_at'   => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public static function platforms(): array
    {
        return ['facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'youtube', 'pinterest', 'telegram'];
    }
}
