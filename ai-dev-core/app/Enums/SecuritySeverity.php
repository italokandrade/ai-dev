<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SecuritySeverity: string implements HasLabel, HasColor
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Informational = 'informational';

    public function getLabel(): string
    {
        return match ($this) {
            self::Critical => 'Crítico',
            self::High => 'Alto',
            self::Medium => 'Médio',
            self::Low => 'Baixo',
            self::Informational => 'Informativo',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'info',
            self::Informational => 'gray',
        };
    }

    public function isBlocking(): bool
    {
        return in_array($this, [self::Critical, self::High]);
    }
}
