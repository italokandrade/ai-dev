<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KnowledgeArea: string implements HasLabel
{
    case Backend = 'backend';
    case Frontend = 'frontend';
    case Database = 'database';
    case Filament = 'filament';
    case Devops = 'devops';
    case Security = 'security';
    case Performance = 'performance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Backend => 'Backend',
            self::Frontend => 'Frontend',
            self::Database => 'Database',
            self::Filament => 'Filament',
            self::Devops => 'DevOps',
            self::Security => 'Segurança',
            self::Performance => 'Performance',
        };
    }
}
