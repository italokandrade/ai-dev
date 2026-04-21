<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Developer = 'developer';
    case QA = 'qa';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Developer => 'Desenvolvedor',
            self::QA => 'Auditor QA',
            self::Guest => 'Convidado',
        };
    }
}
