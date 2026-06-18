<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

enum BenchmarkMetricFormat: string
{
    case Count = 'count';
    case Decimal = 'decimal';
    case Percent = 'percent';
    case Years = 'years';
    case Minutes = 'minutes';
}
