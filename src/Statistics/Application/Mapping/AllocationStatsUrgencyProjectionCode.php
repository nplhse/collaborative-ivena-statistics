<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Integer codes for urgency in allocation_stats_projection (matches allocation.urgency 1..3).
 */
enum AllocationStatsUrgencyProjectionCode: int
{
    case Emergency = 1;

    case Inpatient = 2;

    case Outpatient = 3;

    /**
     * Maps persisted allocation.urgency (int or numeric string) to a projection code.
     */
    public static function tryFromDbValue(mixed $urgency): ?self
    {
        if (null === $urgency || '' === $urgency) {
            return null;
        }

        $int = (int) $urgency;

        return self::tryFrom($int);
    }

    /**
     * Translation key aligned with overview/analysis charts (U1–U3).
     */
    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::Emergency => 'stats.overview.hospital_summary.urgency_u1',
            self::Inpatient => 'stats.overview.hospital_summary.urgency_u2',
            self::Outpatient => 'stats.overview.hospital_summary.urgency_u3',
        };
    }
}
