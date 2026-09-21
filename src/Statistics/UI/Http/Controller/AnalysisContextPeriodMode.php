<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

enum AnalysisContextPeriodMode: string
{
    case Full = 'full';
    case MonthOnly = 'month_only';
    case Hidden = 'hidden';
}
