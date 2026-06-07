<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

enum CaseFlowInsightSeverity: string
{
    case Info = 'info';
    case Elevated = 'elevated';
    case Critical = 'critical';
}
