<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * Obstetric care: none, a delivery ward, or a perinatal centre that takes preterm births.
 */
enum ObstetricsLevel: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case PERINATAL = 'perinatal';

    public function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::BASIC => 1,
            self::PERINATAL => 2,
        };
    }
}
