<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

final class ExplorerQueryKeys
{
    public const string CHART_TOP = 'chartTop';

    public const string DATA_SOURCE = 'dataSource';

    public const string USE_PAGE_SCOPE = 'usePageScope';

    public const string GUIDE = 'guide';

    public const string ROW = 'row';

    public const string COLUMN = 'column';

    public const string GRAIN = 'grain';

    public const string COLUMN_GRAIN = 'columnGrain';

    public const string METRIC = 'metric';
}
