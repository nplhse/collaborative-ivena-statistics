<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * Trauma care a hospital provides, from none to a supraregional trauma centre.
 */
enum TraumaLevel: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case REGIONAL = 'regional';
    case SUPRA = 'supra';

    public function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::BASIC => 1,
            self::REGIONAL => 2,
            self::SUPRA => 3,
        };
    }
}
