<?php

namespace App\Services;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FilamentShieldPermissionSyncService
{
    /**
     * Sincroniza permissões descobertas pelo Filament Shield.
     * Novas Resources, Pages e Widgets entram automaticamente no banco e são
     * concedidas apenas ao super_admin. Os demais perfis continuam sem acesso
     * até configuração manual no cadastro de perfis.
     */
    public static function sync(string $panelId = 'admin'): int
    {
        if (! self::canUsePermissionTables()) {
            return 0;
        }

        try {
            $permissionNames = self::desiredPermissionNames();
        } catch (\Throwable) {
            return 0;
        }

        if ($permissionNames->isEmpty()) {
            return 0;
        }

        $guardName = self::guardName($panelId);
        $created = 0;

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);

            if ($permission->wasRecentlyCreated) {
                $created++;
            }
        }

        self::giveSuperAdminPermissions($permissionNames, $guardName);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $created;
    }

    private static function canUsePermissionTables(): bool
    {
        try {
            return Schema::hasTable(config('permission.table_names.permissions', 'permissions'))
                && Schema::hasTable(config('permission.table_names.roles', 'roles'))
                && Schema::hasTable(config('permission.table_names.role_has_permissions', 'role_has_permissions'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return Collection<int, string>
     */
    private static function desiredPermissionNames(): Collection
    {
        return collect(FilamentShield::getAllResourcePermissionsWithLabels())->keys()
            ->merge(collect(FilamentShield::getPages())->flatMap(fn (array $page): array => array_keys($page['permissions'] ?? [])))
            ->merge(collect(FilamentShield::getWidgets())->flatMap(fn (array $widget): array => array_keys($widget['permissions'] ?? [])))
            ->merge(collect(FilamentShield::getCustomPermissions())->keys())
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '')
            ->unique()
            ->values();
    }

    private static function guardName(string $panelId): string
    {
        try {
            return Filament::getPanel($panelId)->getAuthGuard();
        } catch (\Throwable) {
            return config('auth.defaults.guard', 'web');
        }
    }

    /**
     * @param  Collection<int, string>  $permissionNames
     */
    private static function giveSuperAdminPermissions(Collection $permissionNames, string $guardName): void
    {
        if (! config('filament-shield.super_admin.enabled', true)) {
            return;
        }

        $superAdmin = Role::query()->firstOrCreate([
            'name' => config('filament-shield.super_admin.name', 'super_admin'),
            'guard_name' => $guardName,
        ]);

        $superAdmin->givePermissionTo($permissionNames->all());
    }
}
