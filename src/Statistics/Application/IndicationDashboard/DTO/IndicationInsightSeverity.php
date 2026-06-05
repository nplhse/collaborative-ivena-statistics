<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

enum IndicationInsightSeverity: string
{
    case Critical = 'critical';

    case Elevated = 'elevated';

    case Neutral = 'neutral';
}
