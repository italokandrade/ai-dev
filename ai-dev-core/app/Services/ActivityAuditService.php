<?php

namespace App\Services;

use App\Models\ToolCallLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

class ActivityAuditService
{
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'api_key',
        'credential',
        'password',
        'secret',
        'token',
        'key',
        'hash',
    ];

    private const array EXACT_MODEL_ALLOWLIST = [
        SpatiePermission::class,
        SpatieRole::class,
    ];

    private const array MODEL_EXCLUSIONS = [
        Activity::class,
        ToolCallLog::class,
    ];

    public static function register(): void
    {
        self::registerModelFallbackLogger();
        self::registerPermissionMutationLogger();
    }

    private static function registerModelFallbackLogger(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $eventName, array $payload) use ($event): void {
                $model = $payload[0] ?? null;

                if (! $model instanceof Model || ! self::shouldFallbackAudit($model)) {
                    return;
                }

                $properties = self::propertiesFor($model, $event);

                activity()
                    ->event($event)
                    ->performedOn($model)
                    ->causedBy(auth()->user())
                    ->withProperties($properties)
                    ->log(SystemSurfaceMapService::modelLabel($model::class).' '.$event);
            });
        }
    }

    private static function registerPermissionMutationLogger(): void
    {
        Event::listen(RoleAttachedEvent::class, fn (RoleAttachedEvent $event) => self::logPermissionMutation(
            model: $event->model,
            event: 'role_attached',
            relation: 'roles',
            values: self::normalizeRelationPayload($event->rolesOrIds),
            description: 'Perfil atribuído'
        ));

        Event::listen(RoleDetachedEvent::class, fn (RoleDetachedEvent $event) => self::logPermissionMutation(
            model: $event->model,
            event: 'role_detached',
            relation: 'roles',
            values: self::normalizeRelationPayload($event->rolesOrIds),
            description: 'Perfil removido'
        ));

        Event::listen(PermissionAttachedEvent::class, fn (PermissionAttachedEvent $event) => self::logPermissionMutation(
            model: $event->model,
            event: 'permission_attached',
            relation: 'permissions',
            values: self::normalizeRelationPayload($event->permissionsOrIds),
            description: 'Permissão atribuída'
        ));

        Event::listen(PermissionDetachedEvent::class, fn (PermissionDetachedEvent $event) => self::logPermissionMutation(
            model: $event->model,
            event: 'permission_detached',
            relation: 'permissions',
            values: self::normalizeRelationPayload($event->permissionsOrIds),
            description: 'Permissão removida'
        ));
    }

    private static function shouldFallbackAudit(Model $model): bool
    {
        $class = $model::class;

        if (in_array($class, self::MODEL_EXCLUSIONS, true)) {
            return false;
        }

        if (in_array(LogsActivity::class, class_uses_recursive($model), true)) {
            return false;
        }

        return str_starts_with($class, 'App\\Models\\')
            || in_array($class, self::EXACT_MODEL_ALLOWLIST, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function propertiesFor(Model $model, string $event): array
    {
        if ($event === 'created') {
            return [
                'attributes' => self::sanitizePayload($model->getAttributes()),
            ];
        }

        if ($event === 'updated') {
            $changes = Arr::except($model->getChanges(), ['updated_at']);

            return [
                'old' => self::sanitizePayload(Arr::only($model->getOriginal(), array_keys($changes))),
                'attributes' => self::sanitizePayload($changes),
            ];
        }

        return [
            'old' => self::sanitizePayload($model->getOriginal()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function sanitizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::sanitizePayload($value);

                continue;
            }

            if (is_string($value) && strlen($value) > 2000) {
                $payload[$key] = mb_substr($value, 0, 2000).'...';
            }
        }

        return $payload;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = Str::lower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, mixed>
     */
    private static function normalizeRelationPayload(mixed $values): array
    {
        return collect(Arr::wrap($values))
            ->map(function (mixed $value): mixed {
                if ($value instanceof Model) {
                    return [
                        'id' => $value->getKey(),
                        'name' => $value->getAttribute('name'),
                        'type' => $value::class,
                    ];
                }

                return $value;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function logPermissionMutation(Model $model, string $event, string $relation, array $values, string $description): void
    {
        activity()
            ->event($event)
            ->performedOn($model)
            ->causedBy(auth()->user())
            ->withProperties([
                $relation => $values,
            ])
            ->log($description);
    }
}
