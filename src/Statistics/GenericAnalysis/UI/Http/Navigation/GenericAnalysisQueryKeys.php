<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Navigation;

final class GenericAnalysisQueryKeys
{
    public const string PRIMARY = 'ga_primary';

    public const string SERIES = 'ga_series';

    public const string INCLUDE_NULL = 'ga_include_null';

    public const string REF_PRESET = 'ga_ref';

    public const string LAYOUT = 'ga_layout';

    public const string METRICS = 'ga_metrics';

    public const string VISUAL_METRIC = 'ga_visual_metric';

    public const string TOP = 'ga_top';

    public const string CHART = 'ga_chart';

    public const string SERIES_MODE = 'ga_series_mode';

    public const string DISPLAY = 'ga_display';

    public const string CHART_METRICS = 'ga_chart_metrics';

    public const string DATA_SOURCE = 'ga_data_source';

    public const string HOSPITAL_POPULATION = 'ga_hospital_population';

    public const string PRESET_CUSTOM = 'custom';

    /** @var list<string> */
    public const array REMOVE_CUSTOM = [
        self::PRIMARY,
        self::SERIES,
        self::INCLUDE_NULL,
        self::REF_PRESET,
        self::METRICS,
        self::VISUAL_METRIC,
        self::CHART,
        self::SERIES_MODE,
        self::DISPLAY,
        self::CHART_METRICS,
        self::DATA_SOURCE,
        self::HOSPITAL_POPULATION,
    ];
}
