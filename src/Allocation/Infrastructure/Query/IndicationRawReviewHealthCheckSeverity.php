<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

enum IndicationRawReviewHealthCheckSeverity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Fail = 'fail';
}
