<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\AllocationRowNormalizationTrait;

final class TraitHelper
{
    use AllocationRowNormalizationTrait {
        normalizeGender as private traitNormalizeGender;
        normalizeTransportType as private traitNormalizeTransportType;
        normalizeBoolean as private traitNormalizeBoolean;
        normalizeAge as private traitNormalizeAge;
        normalizeUrgencyFromPZC as private traitNormalizeUrgencyFromPZC;
        combineDateAndTime as private traitCombineDateAndTime;
        normalizeImportDatePart as private traitNormalizeImportDatePart;
        getStringOrNull as private traitGetStringOrNull;
        normalizeDispatchArea as private traitNormalizeDispatchArea;
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

    public static function normalizeUrgencyFromPZC(?string $value): ?int
    {
        return self::traitNormalizeUrgencyFromPZC($value);
    }

    public static function combineDateAndTime(?string $date, ?string $time): ?string
    {
        return self::traitCombineDateAndTime($date, $time);
    }

    public static function normalizeImportDatePart(?string $date): ?string
    {
        return self::traitNormalizeImportDatePart($date);
    }

    /**
     * @param array<string, string|null> $row
     */
    public static function getStringOrNull(array $row, string $key): ?string
    {
        return self::traitGetStringOrNull($row, $key);
    }

    public static function normalizeDispatchArea(?string $value): ?string
    {
        return self::traitNormalizeDispatchArea($value);
    }
}
