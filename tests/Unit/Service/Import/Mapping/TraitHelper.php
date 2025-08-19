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
        normalizeDateTimeString as private traitNormalizeDateTimeString;
        chooseCreatedAt as private traitChooseCreatedAt;
        getStringOrNull as private traitGetStringOrNull;
    }

    public static function normalizeGender(?string $v): string
    {
        return self::traitNormalizeGender($v);
    }

    public static function normalizeTransportType(?string $v): ?string
    {
        return self::traitNormalizeTransportType($v);
    }

    public static function normalizeBoolean(?string $v): ?bool
    {
        return self::traitNormalizeBoolean($v);
    }

    public static function normalizeAge(?string $v): ?int
    {
        return self::traitNormalizeAge($v);
    }

    public static function combineDateAndTime(?string $d, ?string $t): ?string
    {
        return self::traitCombineDateAndTime($d, $t);
    }

    public static function normalizeDateTimeString(string $v): string
    {
        return self::traitNormalizeDateTimeString($v);
    }

    public static function chooseCreatedAt(?string $c, ?string $e): ?string
    {
        return self::traitChooseCreatedAt($c, $e);
    }

    public static function getStringOrNull(array $row, string $key): ?string
    {
        return self::traitGetStringOrNull($row, $key);
    }
}
