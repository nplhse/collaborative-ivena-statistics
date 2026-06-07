<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Shift-oriented buckets in allocation_stats_projection (derived from created_at hour).
 */
enum AllocationStatsShiftBucketProjectionCode: int
{
    case NightShift = 1;

    case EarlyShift = 2;

    case LateShift = 3;

    /** @return list<self> */
    public static function displayOrder(): array
    {
        return [
            self::EarlyShift,
            self::LateShift,
            self::NightShift,
        ];
    }

    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::NightShift => 'stats.indication.shift_bucket.night',
            self::EarlyShift => 'stats.indication.shift_bucket.early',
            self::LateShift => 'stats.indication.shift_bucket.late',
        };
    }
}
