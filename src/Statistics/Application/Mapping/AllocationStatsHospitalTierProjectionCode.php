<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Allocation\Domain\Enum\HospitalTier;

/**
 * Integer codes for hospital tier in allocation_stats_projection (stable for analytics).
 */
enum AllocationStatsHospitalTierProjectionCode: int
{
    case Basic = 1;

    case Extended = 2;

    case Full = 3;

    public static function tryFromHospitalTier(?HospitalTier $tier): ?self
    {
        if (!$tier instanceof HospitalTier) {
            return null;
        }

        return match ($tier) {
            HospitalTier::BASIC => self::Basic,
            HospitalTier::EXTENDED => self::Extended,
            HospitalTier::FULL => self::Full,
        };
    }

    public static function tryFromTierDbValue(mixed $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof HospitalTier) {
            return self::tryFromHospitalTier($value);
        }

        $tier = HospitalTier::tryFrom((string) $value);

        return self::tryFromHospitalTier($tier);
    }

    public function toHospitalTier(): HospitalTier
    {
        return match ($this) {
            self::Basic => HospitalTier::BASIC,
            self::Extended => HospitalTier::EXTENDED,
            self::Full => HospitalTier::FULL,
        };
    }
}
