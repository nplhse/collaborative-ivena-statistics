<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportBatchRunItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Queued = 'queued';
    case DispatchFailed = 'dispatch_failed';
    case Interrupted = 'interrupted';
    case Skipped = 'skipped';
    /** The item's run completed (ADR 016, batch re-imports). */
    case Completed = 'completed';
    /** The item's run completed with rejected rows. */
    case Partial = 'partial';
    /** The item's run failed. */
    case Failed = 'failed';
    /** A newer run was registered for the import before this item's run finished. */
    case Superseded = 'superseded';

    /**
     * Statuses of items whose work is not done yet. A batch is finished when none of its items has one.
     *
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Pending, self::Running, self::Queued, self::Interrupted];
    }

    /**
     * The item status mirroring a run that ended with $status.
     */
    public static function fromRunStatus(ImportRunStatus $status): self
    {
        return match ($status) {
            ImportRunStatus::Completed => self::Completed,
            ImportRunStatus::Partial => self::Partial,
            ImportRunStatus::Failed => self::Failed,
            ImportRunStatus::Superseded => self::Superseded,
            default => throw new \InvalidArgumentException(\sprintf('Run status "%s" is not terminal.', $status->value)),
        };
    }
}
