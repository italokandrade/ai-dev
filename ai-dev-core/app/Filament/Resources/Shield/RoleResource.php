<?php

namespace App\Filament\Resources\Shield;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as BaseRoleResource;

class RoleResource extends BaseRoleResource
{
    protected static ?string $navigationLabel = 'Perfis de Usuários';

    protected static ?string $modelLabel = 'Perfil de Usuário';

    protected static ?string $pluralModelLabel = 'Perfis de Usuários';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    protected static ?int $navigationSort = 70; // Acima de Usuários (80)
}
