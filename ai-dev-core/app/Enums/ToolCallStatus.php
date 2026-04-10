<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ToolCallStatus: string implements HasLabel, HasColor
{
    case Success = 'success';
    case Error = 'error';
    case Blocked = 'blocked';
    case Timeout = 'timeout';

    public function getLabel(): string
    {
        return match ($this) {
            self::Success => 'Sucesso',
            self::Error => 'Erro',
            self::Blocked => 'Bloqueado',
            self::Timeout => 'Timeout',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Success => 'success',
            self::Error => 'danger',
            self::Blocked => 'warning',
            self::Timeout => 'warning',
        };
    }
}
