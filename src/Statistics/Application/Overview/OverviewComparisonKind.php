<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

/**
 * Which previous period the overview compares with; also the suffix of its translation key.
 */
enum OverviewComparisonKind: string
{
    case Last12Months = 'last_12_months';
    case Year = 'year';
    case Quarter = 'quarter';
    case Month = 'month';
    case YearToDate = 'year_to_date';
    case QuarterToDate = 'quarter_to_date';
    case MonthToDate = 'month_to_date';
}
