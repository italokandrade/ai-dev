<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum Priority: string implements HasLabel, HasColor, HasIcon
{
    case Normal = 'normal';
    case Medium = 'medium';
    case High   = 'high';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Normal => 'Padrão',
            self::Medium => 'Média',
            self::High   => 'Alta',
        };
    }

    public function getColor(): string|array|null
    {
        return 'gray';
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Normal => 'heroicon-o-minus',
            self::Medium => 'heroicon-o-arrow-up',
            self::High   => 'heroicon-o-chevron-double-up',
        };
    }

    public function getNumericValue(): int
    {
        return match ($this) {
            self::Normal => 10,
            self::Medium => 50,
            self::High   => 90,
        };
    }
}
