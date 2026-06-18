<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

/**
 * Three allocations where precise timestamp medians differ from medians on rounded transport_time_minutes.
 *
 * Precise minutes: 20.5, 20.0, 21.5 → median 20.5
 * Rounded minutes: 21, 20, 22 → median 21
 */
final class PreciseTransportTimeScenarios
{
    public const float PRECISE_MEDIAN_MINUTES = 20.5;

    public const float ROUNDED_MINUTES_MEDIAN = 21.0;

    public const float PRECISE_MEAN_MINUTES = 20.666666666666668;

    /**
     * @return list<array{createdAt: \DateTimeImmutable, arrivalAt: \DateTimeImmutable}>
     */
    public static function allocations(): array
    {
        return [
            [
                'createdAt' => new \DateTimeImmutable('2026-03-01 10:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2026-03-01 10:20:30'),
            ],
            [
                'createdAt' => new \DateTimeImmutable('2026-03-01 11:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2026-03-01 11:20:00'),
            ],
            [
                'createdAt' => new \DateTimeImmutable('2026-03-01 12:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2026-03-01 12:21:30'),
            ],
        ];
    }

    /**
     * 9.5 precise minutes → transport_time_minutes = 10 → bucket 10_20 (not under_10).
     *
     * @return array{createdAt: \DateTimeImmutable, arrivalAt: \DateTimeImmutable}
     */
    public static function subTenMinuteRoundedToTenBucket(): array
    {
        return [
            'createdAt' => new \DateTimeImmutable('2026-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 10:09:30'),
        ];
    }
}
