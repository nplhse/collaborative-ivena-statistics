<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Navigation;

final class StatisticsQueryKeys
{
    public const string SCOPE = 'scope';
    public const string HOSPITAL = 'hospital';
    public const string COHORT = 'cohort';
    public const string STATE = 'state';
    public const string PERIOD = 'period';
    public const string YEAR = 'year';
    public const string MONTH = 'month';

    public const string ANALYSIS = 'analysis';
    public const string VIEW = 'view';
    public const string CHART = 'chart';
    public const string DIMENSION = 'dimension';
    public const string CHART_MEASURE = 'chart_measure';
    public const string ROWS = 'rows';
    public const string COLS = 'cols';
    public const string MEASURE = 'measure';

    public const string COMPARISON_SCOPE = 'comparison_scope';
    public const string COMPARISON_COHORT = 'comparison_cohort';
    public const string COMPARISON_STATE = 'comparison_state';
    public const string COMPARISON_PERIOD = 'comparison_period';
    public const string COMPARISON_YEAR = 'comparison_year';
    public const string COMPARISON_MONTH = 'comparison_month';

    public const string REPORT = 'report';
    public const string LIMIT = 'limit';

    /** @var list<string> */
    public const array REMOVE_SCOPE_DEPENDENT = [
        self::HOSPITAL,
        self::COHORT,
        self::STATE,
    ];

    /** @var list<string> */
    public const array REMOVE_PERIOD_DEPENDENT = [
        self::YEAR,
        self::MONTH,
    ];

    /** @var list<string> */
    public const array REMOVE_MONTH_DEPENDENT = [
        self::MONTH,
    ];

    /** @var list<string> */
    public const array PIVOT_STALE = [
        self::DIMENSION,
        self::CHART_MEASURE,
        self::CHART,
    ];

    /** @var list<string> */
    public const array CHART_TABLE_STALE = [
        self::ROWS,
        self::COLS,
        self::MEASURE,
    ];

    /** @var list<string> */
    public const array DRAWER_FILTERS = [
        'gender',
        'urgency',
        'age_range',
        'feature',
        'department',
        'speciality',
        'dispatch_area',
        'hospital_attribute',
    ];

    /** @var list<string> */
    public const array REPORT_STALE = [
        self::REPORT,
        self::LIMIT,
        self::CHART,
    ];
}
