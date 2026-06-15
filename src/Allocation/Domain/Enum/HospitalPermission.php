<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum HospitalPermission: int
{
    case View = 1;
    case Statistics = 2;
    case Import = 4;
    case Export = 8;
    case Benchmarking = 16;

    /**
     * @return list<self>
     */
    public static function assignableCases(): array
    {
        return [
            self::View,
            self::Statistics,
            self::Benchmarking,
            self::Import,
            self::Export,
        ];
    }
}
