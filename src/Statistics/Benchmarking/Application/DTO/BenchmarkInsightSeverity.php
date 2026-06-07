<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

enum BenchmarkInsightSeverity: string
{
    case Critical = 'critical';
    case Elevated = 'elevated';
    case Neutral = 'neutral';
}
