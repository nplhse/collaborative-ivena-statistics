<?php

namespace App\Service\Statistics\Util;

final class Period
{
    public const ALL = 'all';
    public const YEAR = 'year';
    public const QUARTER = 'quarter';
    public const MONTH = 'month';
    public const WEEK = 'week';
    public const DAY = 'day';

    public const ALL_ANCHOR_DATE = '2010-01-01';

    /**
     * @return string[]
     */
    public static function allGranularities(): array
    {
        return [self::ALL, self::YEAR, self::QUARTER, self::MONTH, self::WEEK, self::DAY];
    }
}
