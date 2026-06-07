<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

enum BenchmarkInsightDirection: string
{
    case Above = 'above';
    case Below = 'below';
    case Neutral = 'neutral';
}
