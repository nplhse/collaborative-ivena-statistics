<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

/**
 * Whitelist: logical keys in panel config → allocation_stats_projection columns (box plot only).
 */
enum DistributionNumericMetric: string
{
    case Age = 'age';

    case TransportTimeMinutes = 'transport_time_minutes';

    public function sqlColumn(): string
    {
        return match ($this) {
            self::Age => 'age',
            self::TransportTimeMinutes => 'transport_time_minutes',
        };
    }
}
