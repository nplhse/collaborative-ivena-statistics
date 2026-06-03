<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;

/**
 * Identifies one hospital cohort as location × tier (3×3 matrix from domain enums).
 */
final readonly class HospitalCohortKey implements \Stringable
{
    /** @var array<string, string> */
    private const array LEGACY_VALUE_ALIASES = [
        'urban_advanced' => 'urban_extended',
        'rural_advanced' => 'rural_extended',
    ];

    public function __construct(
        public HospitalLocation $location,
        public HospitalTier $tier,
    ) {
    }

    public function value(): string
    {
        return strtolower($this->location->value).'_'.strtolower($this->tier->value);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value();
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (HospitalLocation::cases() as $location) {
            foreach (HospitalTier::cases() as $tier) {
                $keys[] = new self($location, $tier);
            }
        }

        return $keys;
    }

    public static function tryFrom(string $raw): ?self
    {
        if ('' === $raw) {
            return null;
        }

        $normalized = self::LEGACY_VALUE_ALIASES[$raw] ?? $raw;

        foreach (self::all() as $key) {
            if ($key->value() === $normalized) {
                return $key;
            }
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->location === $other->location && $this->tier === $other->tier;
    }

    public function locationProjectionCode(): AllocationStatsHospitalLocationProjectionCode
    {
        return match ($this->location) {
            HospitalLocation::URBAN => AllocationStatsHospitalLocationProjectionCode::Urban,
            HospitalLocation::MIXED => AllocationStatsHospitalLocationProjectionCode::Mixed,
            HospitalLocation::RURAL => AllocationStatsHospitalLocationProjectionCode::Rural,
        };
    }

    public function tierProjectionCode(): AllocationStatsHospitalTierProjectionCode
    {
        return match ($this->tier) {
            HospitalTier::BASIC => AllocationStatsHospitalTierProjectionCode::Basic,
            HospitalTier::EXTENDED => AllocationStatsHospitalTierProjectionCode::Extended,
            HospitalTier::FULL => AllocationStatsHospitalTierProjectionCode::Full,
        };
    }
}
