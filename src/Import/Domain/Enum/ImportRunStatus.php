<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

/**
 * Lifecycle of one deliberate processing request of an import (ADR 016).
 *
 * Queued and Running are open; every other status is terminal and is reached exactly once.
 */
enum ImportRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Completed, self::Partial, self::Failed, self::Superseded => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Queued, self::Running];
    }

    public static function fromImportStatus(ImportStatus $status): self
    {
        return match ($status) {
            ImportStatus::COMPLETED => self::Completed,
            ImportStatus::PARTIAL => self::Partial,
            ImportStatus::FAILED, ImportStatus::CANCELLED => self::Failed,
            ImportStatus::PENDING, ImportStatus::RUNNING => throw new \InvalidArgumentException(\sprintf('Import status "%s" is not final.', $status->value)),
        };
    }
}
