<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum MetricType: string
{
    case Count = 'count';
    case Relative = 'relative';
    case NumericAggregate = 'numeric_aggregate';
    case Distinct = 'distinct';
    case Inferential = 'inferential';
}
