<?php

namespace App\Filament\Resources;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as BaseRoleResource;

class RoleResource extends BaseRoleResource
{
    protected static ?string $navigationLabel = 'Perfis de Usuários';

    protected static ?string $modelLabel = 'Perfil de Usuário';

    protected static ?string $pluralModelLabel = 'Perfis de Usuários';

    protected static string|\UnitEnum|null $navigationGroup = 'Segurança';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'perfis-de-usuarios';

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return 'Perfis de Usuários';
    }

    public static function getModelLabel(): string
    {
        return 'Perfil de Usuário';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Perfis de Usuários';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Segurança';
    }

    public static function getNavigationSort(): ?int
    {
        return 70;
    }
}
