<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProjectStatus: string implements HasColor, HasIcon, HasLabel
{
    case Active = 'active';
    case Paused = 'paused';
    case Scaffolding = 'scaffolding';
    case ScaffoldFailed = 'scaffold_failed';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Scaffolding => 'Instalando scaffold',
            self::ScaffoldFailed => 'Scaffold incompleto',
            self::Archived => 'Arquivado',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'primary',
            self::Paused => 'gray',
            self::Scaffolding => 'warning',
            self::ScaffoldFailed => 'danger',
            self::Archived => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Active => 'heroicon-o-bolt',
            self::Paused => 'heroicon-o-pause-circle',
            self::Scaffolding => 'heroicon-o-wrench-screwdriver',
            self::ScaffoldFailed => 'heroicon-o-exclamation-triangle',
            self::Archived => 'heroicon-o-archive-box',
        };
    }
}
