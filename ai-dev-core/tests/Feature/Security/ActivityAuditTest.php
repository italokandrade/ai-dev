<?php

use App\Filament\Widgets\DashboardChat;
use App\Models\TaskTransition;
use App\Models\User;
use App\Services\FilamentShieldPermissionSyncService;
use App\Services\SystemSurfaceMapService;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('fallback audit logs app models without LogsActivity automatically', function () {
    Activity::query()->delete();

    $transition = TaskTransition::create([
        'entity_type' => 'task',
        'entity_id' => (string) Str::uuid(),
        'from_status' => 'pending',
        'to_status' => 'in_progress',
        'triggered_by' => 'test',
        'metadata' => ['reason' => 'coverage'],
    ]);

    expect(Activity::query()
        ->where('subject_type', TaskTransition::class)
        ->where('subject_id', $transition->id)
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

test('fallback audit logs spatie role model changes', function () {
    Activity::query()->delete();

    $role = Role::create([
        'name' => 'security-test',
        'guard_name' => 'web',
    ]);

    expect(Activity::query()
        ->where('subject_type', Role::class)
        ->where('subject_id', $role->id)
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

test('audit logs role assignments through spatie events', function () {
    Activity::query()->delete();

    $user = User::factory()->create();
    $role = Role::create([
        'name' => 'assignment-test',
        'guard_name' => 'web',
    ]);

    $user->assignRole($role);

    expect(Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('event', 'role_attached')
        ->exists())->toBeTrue();
});

test('system surface map exposes future auditable models to activity filters', function () {
    $labels = SystemSurfaceMapService::activitySubjectLabels();

    expect($labels)
        ->toHaveKey(TaskTransition::class)
        ->and($labels[TaskTransition::class])->toBe('Transição de Status')
        ->and($labels)->toHaveKey(Role::class)
        ->and($labels[Role::class])->toBe('Perfil de Usuário')
        ->and($labels)->not->toHaveKey('App\Models\Role');
});

test('activity subject filter options do not repeat aliased module labels', function () {
    $options = SystemSurfaceMapService::activitySubjectFilterOptions([
        Role::class,
        'App\Models\Role',
    ]);

    expect(array_count_values($options)['Perfil de Usuário'] ?? 0)->toBe(1)
        ->and(SystemSurfaceMapService::subjectTypesForFilter('security.roles'))
        ->toContain(Role::class, 'App\Models\Role');
});

test('system surface map discovers filament widgets automatically', function () {
    $surfaces = SystemSurfaceMapService::filamentSurfaces();

    expect(collect($surfaces['widgets'])->pluck('class')->all())
        ->toContain(DashboardChat::class);
});

test('shield permission sync creates new permissions and grants only super admin', function () {
    $otherRole = Role::create([
        'name' => 'regular-profile',
        'guard_name' => 'web',
    ]);

    FilamentShieldPermissionSyncService::sync();

    expect(Permission::where('name', 'View:DashboardChat')->exists())->toBeTrue();

    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();

    expect($superAdmin->hasPermissionTo('View:DashboardChat'))->toBeTrue()
        ->and($otherRole->fresh()->hasPermissionTo('View:DashboardChat'))->toBeFalse();
});
