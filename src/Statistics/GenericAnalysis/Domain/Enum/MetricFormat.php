<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum MetricFormat: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Percent = 'percent';
    case Minutes = 'minutes';
}
