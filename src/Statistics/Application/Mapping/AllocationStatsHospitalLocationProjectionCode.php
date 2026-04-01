<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Allocation\Domain\Enum\HospitalLocation;

/**
 * Integer codes for hospital location in allocation_stats_projection (stable for analytics).
 */
enum AllocationStatsHospitalLocationProjectionCode: int
{
    case Urban = 1;

    case Mixed = 2;

    case Rural = 3;

    public static function tryFromHospitalLocation(?HospitalLocation $location): ?self
    {
        if (!$location instanceof HospitalLocation) {
            return null;
        }

        return match ($location) {
            HospitalLocation::URBAN => self::Urban,
            HospitalLocation::MIXED => self::Mixed,
            HospitalLocation::RURAL => self::Rural,
        };
    }

    public static function tryFromLocationDbValue(mixed $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof HospitalLocation) {
            return self::tryFromHospitalLocation($value);
        }

        $location = HospitalLocation::tryFrom((string) $value);

        return self::tryFromHospitalLocation($location);
    }

    public function toHospitalLocation(): HospitalLocation
    {
        return match ($this) {
            self::Urban => HospitalLocation::URBAN,
            self::Mixed => HospitalLocation::MIXED,
            self::Rural => HospitalLocation::RURAL,
        };
    }
}
