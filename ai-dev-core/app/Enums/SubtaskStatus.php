<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SubtaskStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Running = 'running';
    case QaAudit = 'qa_audit';
    case Success = 'success';
    case Error = 'error';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Running => 'Executando',
            self::QaAudit => 'Auditoria QA',
            self::Success => 'Sucesso',
            self::Error => 'Erro',
            self::Blocked => 'Bloqueada',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::QaAudit => 'warning',
            self::Success => 'success',
            self::Error => 'danger',
            self::Blocked => 'warning',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Running => 'heroicon-o-cog',
            self::QaAudit => 'heroicon-o-magnifying-glass',
            self::Success => 'heroicon-o-check-circle',
            self::Error => 'heroicon-o-x-circle',
            self::Blocked => 'heroicon-o-lock-closed',
        };
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Blocked],
            self::Running => [self::QaAudit, self::Error],
            self::QaAudit => [self::Success, self::Pending], // Pending = retry
            self::Success => [],
            self::Error => [self::Pending], // retry
            self::Blocked => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions());
    }
}
