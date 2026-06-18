<?php

declare(strict_types=1);

namespace App\Statistics\UI\Application;

enum StatisticsFilterSide: string
{
    case Primary = 'primary';
    case Comparison = 'comparison';
}
