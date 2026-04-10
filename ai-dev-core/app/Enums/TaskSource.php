<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskSource: string implements HasLabel
{
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Sentinel = 'sentinel';
    case CiCd = 'ci_cd';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual (UI)',
            self::Webhook => 'Webhook',
            self::Sentinel => 'Sentinela',
            self::CiCd => 'CI/CD',
        };
    }
}
