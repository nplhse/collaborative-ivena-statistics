<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

enum IndicationCompareInsightSeverity: string
{
    case Critical = 'critical';
    case Elevated = 'elevated';
    case Neutral = 'neutral';
}
