<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

enum BenchmarkMetricFormat: string
{
    case Count = 'count';
    case Percent = 'percent';
    case Years = 'years';
    case Minutes = 'minutes';
}
