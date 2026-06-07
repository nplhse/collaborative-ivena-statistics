<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Calendar day-time buckets in allocation_stats_projection (derived from created_at hour).
 */
enum AllocationStatsDayTimeBucketProjectionCode: int
{
    case Night = 1;

    case Morning = 2;

    case Afternoon = 3;

    case Evening = 4;

    /** @return list<self> */
    public static function displayOrder(): array
    {
        return [
            self::Morning,
            self::Afternoon,
            self::Evening,
            self::Night,
        ];
    }

    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::Night => 'stats.indication.day_time_bucket.night',
            self::Morning => 'stats.indication.day_time_bucket.morning',
            self::Afternoon => 'stats.indication.day_time_bucket.afternoon',
            self::Evening => 'stats.indication.day_time_bucket.evening',
        };
    }
}
