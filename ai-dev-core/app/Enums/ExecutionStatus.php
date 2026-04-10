<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExecutionStatus: string implements HasLabel, HasColor
{
    case Success = 'success';
    case Error = 'error';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';

    public function getLabel(): string
    {
        return match ($this) {
            self::Success => 'Sucesso',
            self::Error => 'Erro',
            self::Timeout => 'Timeout',
            self::RateLimited => 'Rate Limited',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Success => 'success',
            self::Error => 'danger',
            self::Timeout => 'warning',
            self::RateLimited => 'warning',
        };
    }
}
