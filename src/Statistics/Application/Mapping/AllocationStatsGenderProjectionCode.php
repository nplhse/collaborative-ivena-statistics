<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Integer codes for gender in allocation_stats_projection (stable for analytics).
 */
enum AllocationStatsGenderProjectionCode: int
{
    case Male = 1;

    case Female = 2;

    case Other = 3;

    /**
     * Maps persisted allocation.gender letter (M/F/X) to a projection code.
     */
    public static function tryFromDbLetter(?string $gender): ?self
    {
        if (null === $gender || '' === $gender) {
            return null;
        }

        return match (strtoupper($gender)) {
            'M' => self::Male,
            'F' => self::Female,
            'X' => self::Other,
            default => null,
        };
    }

    /**
     * Translation key aligned with {@see \App\Allocation\Domain\Enum\AllocationGender::label()}.
     */
    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::Male => 'label.gender.male',
            self::Female => 'label.gender.female',
            self::Other => 'label.gender.other',
        };
    }
}
