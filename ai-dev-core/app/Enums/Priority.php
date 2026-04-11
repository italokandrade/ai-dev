<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Priority: string implements HasLabel, HasColor
{
    case Normal = 'normal';
    case Medium = 'medium';
    case High = 'high';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Normal => 'Padrão',
            self::Medium => 'Média',
            self::High => 'Alta',
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    public function getNumericValue(): int
    {
        return match ($this) {
            self::Normal => 10,
            self::Medium => 50,
            self::High => 90,
        };
    }
}
