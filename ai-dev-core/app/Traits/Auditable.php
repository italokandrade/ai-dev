<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

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
            
            // Remove timestamps dos valores auditados para não poluir o log
            unset($oldValues['updated_at'], $newValues['updated_at']);
            
            if (!empty($newValues)) {
                static::logAudit($model, 'updated', $oldValues, $newValues);
            }
        });

        static::deleted(function ($model) {
            static::logAudit($model, 'deleted', $model->getOriginal());
        });
    }

    protected static function logAudit($model, string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => (string) $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
