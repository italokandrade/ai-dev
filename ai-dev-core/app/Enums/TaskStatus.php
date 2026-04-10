<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case QaAudit = 'qa_audit';
    case Testing = 'testing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Rollback = 'rollback';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::InProgress => 'Em Progresso',
            self::QaAudit => 'Auditoria QA',
            self::Testing => 'Testando',
            self::Completed => 'Concluída',
            self::Rejected => 'Rejeitada',
            self::Escalated => 'Escalada',
            self::Rollback => 'Rollback',
            self::Failed => 'Falhou',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::QaAudit => 'warning',
            self::Testing => 'primary',
            self::Completed => 'success',
            self::Rejected => 'danger',
            self::Escalated => 'danger',
            self::Rollback => 'warning',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::InProgress => 'heroicon-o-play',
            self::QaAudit => 'heroicon-o-magnifying-glass',
            self::Testing => 'heroicon-o-beaker',
            self::Completed => 'heroicon-o-check-circle',
            self::Rejected => 'heroicon-o-x-circle',
            self::Escalated => 'heroicon-o-exclamation-triangle',
            self::Rollback => 'heroicon-o-arrow-uturn-left',
            self::Failed => 'heroicon-o-x-mark',
        };
    }

    /**
     * Transições válidas a partir deste estado.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress],
            self::InProgress => [self::QaAudit, self::Rollback, self::Failed],
            self::QaAudit => [self::Testing, self::Rejected],
            self::Testing => [self::Completed, self::Failed],
            self::Rejected => [self::InProgress, self::Escalated],
            self::Escalated => [self::InProgress, self::Failed],
            self::Rollback => [self::Failed, self::Pending],
            self::Completed => [],
            self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions());
    }
}
