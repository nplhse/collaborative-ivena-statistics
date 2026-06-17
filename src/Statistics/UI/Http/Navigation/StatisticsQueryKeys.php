<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Navigation;

final class StatisticsQueryKeys
{
    public const string SCOPE = 'scope';
    public const string HOSPITAL = 'hospital';
    public const string COHORT = 'cohort';
    public const string STATE = 'state';
    public const string DISPATCH_AREA = 'dispatch_area';
    public const string PERIOD = 'period';
    public const string YEAR = 'year';
    public const string MONTH = 'month';
    public const string QUARTER = 'quarter';

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
    public const string COMPARISON_DISPATCH_AREA = 'comparison_dispatch_area';
    public const string COMPARISON_PERIOD = 'comparison_period';
    public const string COMPARISON_YEAR = 'comparison_year';
    public const string COMPARISON_MONTH = 'comparison_month';
    public const string COMPARISON_QUARTER = 'comparison_quarter';
    public const string COMPARISON_HOSPITAL = 'comparison_hospital';

    /** @var list<string> */
    public const array REMOVE_COMPARISON_SCOPE_DEPENDENT = [
        self::COMPARISON_HOSPITAL,
        self::COMPARISON_COHORT,
        self::COMPARISON_STATE,
        self::COMPARISON_DISPATCH_AREA,
    ];

    /** @var list<string> */
    public const array REMOVE_COMPARISON_PERIOD_DEPENDENT = [
        self::COMPARISON_YEAR,
        self::COMPARISON_MONTH,
        self::COMPARISON_QUARTER,
    ];

    /** @var list<string> */
    public const array REMOVE_COMPARISON_MONTH_DEPENDENT = [
        self::COMPARISON_MONTH,
    ];

    /** @var list<string> */
    public const array REMOVE_COMPARISON_QUARTER_DEPENDENT = [
        self::COMPARISON_QUARTER,
        self::COMPARISON_MONTH,
    ];

    public const string REPORT = 'report';
    public const string LIMIT = 'limit';

    public const string INDICATION_A = 'indication_a';
    public const string INDICATION_B = 'indication_b';
    public const string SUBJECT_A_TYPE = 'subject_a_type';
    public const string SUBJECT_A_ID = 'subject_a_id';
    public const string SUBJECT_B_TYPE = 'subject_b_type';
    public const string SUBJECT_B_ID = 'subject_b_id';
    public const string INDICATION_GROUP = 'indication_group';

    /** @var list<string> */
    public const array REMOVE_SCOPE_DEPENDENT = [
        self::HOSPITAL,
        self::COHORT,
        self::STATE,
        self::DISPATCH_AREA,
    ];

    /** @var list<string> */
    public const array REMOVE_PERIOD_DEPENDENT = [
        self::YEAR,
        self::MONTH,
        self::QUARTER,
    ];

    /** @var list<string> */
    public const array REMOVE_MONTH_DEPENDENT = [
        self::MONTH,
    ];

    /** @var list<string> */
    public const array REMOVE_QUARTER_DEPENDENT = [
        self::QUARTER,
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
