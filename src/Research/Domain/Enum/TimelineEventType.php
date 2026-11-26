<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * What happened on a data extract request, shown in its timeline next to the comments (ADR 045).
 */
enum TimelineEventType: string
{
    case Submitted = 'submitted';
    case Returned = 'returned';
    case RoundOpened = 'round_opened';
    case Voted = 'voted';
    case RoundClosed = 'round_closed';
    case MemberExcluded = 'member_excluded';
    case VetoResolved = 'veto_resolved';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case QuerySaved = 'query_saved';
    case Released = 'released';
    case ExportRequested = 'export_requested';
    case ExportProvided = 'export_provided';
    case ExportBlocked = 'export_blocked';
    case ExportFailed = 'export_failed';
    case ExportRetried = 'export_retried';
    case ReleaseRevoked = 'release_revoked';
    case ReleaseExpired = 'release_expired';
    case ExportCancelled = 'export_cancelled';

    public function labelKey(): string
    {
        return 'data_request.timeline.event.'.$this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Submitted => 'tabler:send',
            self::Returned, self::VetoResolved => 'tabler:arrow-left',
            self::RoundOpened, self::Voted, self::RoundClosed => 'tabler:checkbox',
            self::MemberExcluded => 'tabler:ban',
            self::Expired => 'tabler:clock',
            self::Withdrawn => 'tabler:x',
            self::QuerySaved => 'tabler:database',
            self::Released => 'tabler:key',
            self::ExportRequested, self::ExportRetried => 'tabler:refresh',
            self::ExportProvided => 'tabler:download',
            self::ExportBlocked, self::ExportFailed => 'tabler:alert-triangle',
            self::ReleaseRevoked => 'tabler:key-off',
            self::ReleaseExpired => 'tabler:clock',
            self::ExportCancelled => 'tabler:x',
        };
    }
}
