<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::logAudit($model, 'created', null, $model->getAttributes());
        });

        static::updating(function ($model) {
            $oldValues = array_intersect_key($model->getOriginal(), $model->getDirty());
            $newValues = $model->getDirty();
            static::logAudit($model, 'updated', $oldValues, $newValues);
        });

        static::deleted(function ($model) {
            static::logAudit($model, 'deleted', $model->getOriginal());
        });
    }

    protected static function logAudit($model, string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        // For 'created' event, we need the ID which is now available
        // For 'updating' event, the ID is already there
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => (string) $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
