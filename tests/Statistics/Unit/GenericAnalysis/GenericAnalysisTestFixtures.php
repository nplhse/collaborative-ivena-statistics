<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;

final class GenericAnalysisTestFixtures
{
    /**
     * @param array<string, int|float|null> $extraMetrics
     */
    public static function resultRow(
        int|string|float|null $bucket,
        int $count,
        int|string|float|null $series = null,
        array $extraMetrics = [],
    ): AnalysisResultRow {
        return new AnalysisResultRow(
            bucket: $bucket,
            metrics: array_merge(['count' => $count], $extraMetrics),
            series: $series,
        );
    }

    /**
     * @param list<string> $metricKeys
     */
    public static function defaultQuery(
        string $primary = 'month',
        ?string $series = null,
        array $metricKeys = [],
    ): AnalysisQuery {
        return new AnalysisQuery(
            primaryDimensionKey: $primary,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
            metricKeys: $metricKeys,
        );
    }
}
