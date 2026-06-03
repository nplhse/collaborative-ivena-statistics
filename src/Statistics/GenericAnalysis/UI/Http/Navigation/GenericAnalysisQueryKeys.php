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

    public const string PRESET_CUSTOM = 'custom';

    /** @var list<string> */
    public const array REMOVE_CUSTOM = [
        self::PRIMARY,
        self::SERIES,
        self::INCLUDE_NULL,
        self::REF_PRESET,
    ];
}
