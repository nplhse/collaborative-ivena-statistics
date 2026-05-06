<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsAnalysisDimension: string
{
    case Total = 'total';
    case Gender = 'gender';
    case Urgency = 'urgency';
    case Resources = 'resources';
    case Features = 'features';
}
