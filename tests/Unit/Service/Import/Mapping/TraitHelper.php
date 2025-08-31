<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import\Mapping;

use App\Service\Import\Mapping\AllocationRowNormalizationTrait;

final class TraitHelper
{
    use AllocationRowNormalizationTrait {
        normalizeGender as private traitNormalizeGender;
        normalizeTransportType as private traitNormalizeTransportType;
        normalizeBoolean as private traitNormalizeBoolean;
        normalizeAge as private traitNormalizeAge;
        combineDateAndTime as private traitCombineDateAndTime;
        getStringOrNull as private traitGetStringOrNull;
    }

    public static function normalizeGender(?string $value): string
    {
        return self::traitNormalizeGender($value);
    }

    public static function normalizeTransportType(?string $value): ?string
    {
        return self::traitNormalizeTransportType($value);
    }

    public static function normalizeBoolean(?string $value): ?bool
    {
        return self::traitNormalizeBoolean($value);
    }

    public static function normalizeAge(?string $value): ?int
    {
        return self::traitNormalizeAge($value);
    }

    public static function combineDateAndTime(?string $date, ?string $time): ?string
    {
        return self::traitCombineDateAndTime($date, $time);
    }

    /**
     * @param array<string, string|null> $row
     */
    public static function getStringOrNull(array $row, string $key): ?string
    {
        return self::traitGetStringOrNull($row, $key);
    }
}
