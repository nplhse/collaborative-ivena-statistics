<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

enum HospitalInsightTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Neutral = 'neutral';
}
