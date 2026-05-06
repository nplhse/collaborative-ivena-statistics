<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsChartMeasure: string
{
    case Absolute = 'absolute';
    case Share = 'share';

    public static function fromQueryValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Absolute;
    }
}
