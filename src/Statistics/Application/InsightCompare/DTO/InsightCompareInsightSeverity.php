<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare\DTO;

enum InsightCompareInsightSeverity: string
{
    case Critical = 'critical';
    case Elevated = 'elevated';
    case Neutral = 'neutral';
}
