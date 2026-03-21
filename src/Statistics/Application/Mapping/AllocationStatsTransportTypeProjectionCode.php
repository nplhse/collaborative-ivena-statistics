<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Integer codes for transport type in allocation_stats_projection (stable for analytics).
 */
enum AllocationStatsTransportTypeProjectionCode: int
{
    case Ground = 1;

    case Air = 2;

    /**
     * Maps persisted allocation.transport_type letter (G/A) to a projection code.
     */
    public static function tryFromDbLetter(?string $transportType): ?self
    {
        if (null === $transportType || '' === $transportType) {
            return null;
        }

        return match (strtoupper($transportType)) {
            'G' => self::Ground,
            'A' => self::Air,
            default => null,
        };
    }
}
