<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum MetricSourceType: string
{
    case Numeric = 'numeric';
    case Categorical = 'categorical';
    case Boolean = 'boolean';
}
