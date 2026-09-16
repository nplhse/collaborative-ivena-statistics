<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

enum InsightNavPlacement: string
{
    case Primary = 'primary';
    case Nested = 'nested';
}
