<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProjectStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Archived => 'Arquivado',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Archived => 'gray',
        };
    }
}
