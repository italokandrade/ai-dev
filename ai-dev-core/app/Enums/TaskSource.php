<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskSource: string implements HasLabel
{
    case Manual = 'manual';
    case Prd = 'prd';
    case Architecture = 'architecture';
    case Specification = 'specification';
    case Webhook = 'webhook';
    case Sentinel = 'sentinel';
    case CiCd = 'ci_cd';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual (UI)',
            self::Prd => 'PRD',
            self::Architecture => 'Arquitetura',
            self::Specification => 'Especificação IA',
            self::Webhook => 'Webhook',
            self::Sentinel => 'Sentinela',
            self::CiCd => 'CI/CD',
        };
    }
}
