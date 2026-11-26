<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * State of the one export run of a release (ADR 045): queued → running → available → expired; a run that hits a
 * technical error is failed and may be queued again, a run with a group below the minimum of hospitals is blocked.
 */
enum ExportRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Available = 'available';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'data_request.export.status.'.$this->value;
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'blue',
            self::Available => 'green',
            self::Blocked, self::Failed => 'red',
            self::Expired, self::Cancelled => 'secondary',
        };
    }

    /** Queued or running: no new release while the export is under way. */
    public function isPending(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }
}
