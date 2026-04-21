<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasRoles, LogsActivity;

    /**
     * Verifica se o usuário tem permissão total (Super Admin).
     * O Shield cria por padrão o papel 'super_admin'.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name', 'super_admin'));
    }

    /**
     * Verifica se o usuário tem permissão de desenvolvimento.
     */
    public function isDeveloper(): bool
    {
        return $this->hasRole(['super_admin', 'developer']);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true; 
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
