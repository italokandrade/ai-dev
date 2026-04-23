<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ModuleStatus: string implements HasLabel, HasColor, HasIcon
{
    case Planned    = 'planned';
    case InProgress = 'in_progress';
    case Testing    = 'testing';
    case Completed  = 'completed';
    case Revision   = 'revision';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned    => 'Planejado',
            self::InProgress => 'Em Desenvolvimento',
            self::Testing    => 'Em Validação',
            self::Completed  => 'Concluído',
            self::Revision   => 'Em Revisão',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Planned    => 'gray',
            self::InProgress => 'primary',
            self::Testing    => 'gray',
            self::Completed  => 'success',
            self::Revision   => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Planned    => 'heroicon-o-clock',
            self::InProgress => 'heroicon-o-arrow-path',
            self::Testing    => 'heroicon-o-beaker',
            self::Completed  => 'heroicon-o-check-circle',
            self::Revision   => 'heroicon-o-arrow-uturn-left',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Planned    => in_array($target, [self::InProgress]),
            self::InProgress => in_array($target, [self::Testing]),
            self::Testing    => in_array($target, [self::Completed, self::InProgress]),
            self::Completed  => in_array($target, [self::Revision]),
            self::Revision   => in_array($target, [self::InProgress]),
        };
    }
}
