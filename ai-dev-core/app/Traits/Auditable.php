<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::logAudit($model, 'created');
        });

        static::updated(function ($model) {
            static::logAudit($model, 'updated');
        });

        static::deleted(function ($model) {
            static::logAudit($model, 'deleted');
        });
    }

    protected static function logAudit($model, string $action): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => $model->id,
            'old_values' => $action === 'updated' ? array_intersect_key($model->getOriginal(), $model->getDirty()) : null,
            'new_values' => $action !== 'deleted' ? $model->getDirty() : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
