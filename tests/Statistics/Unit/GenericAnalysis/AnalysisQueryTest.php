<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use PHPUnit\Framework\TestCase;

final class AnalysisQueryTest extends TestCase
{
    public function testEmptyMetricKeysDefaultToCount(): void
    {
        self::assertSame(['count'], GenericAnalysisTestFixtures::defaultQuery()->resolvedMetricKeys());
    }

    public function testResolvedVisualMetricKeyDefaultsToCount(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery(metricKeys: ['count', 'percent_of_total']);

        self::assertSame('count', $query->resolvedVisualMetricKey());
    }

    public function testResolvedVisualMetricKeyUsesExplicitValue(): void
    {
        $base = GenericAnalysisTestFixtures::defaultQuery(metricKeys: ['count', 'percent_of_total']);
        $query = new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery(
            primaryDimensionKey: $base->primaryDimensionKey,
            scopeCriteria: $base->scopeCriteria,
            periodBounds: $base->periodBounds,
            metricKeys: ['count', 'percent_of_total'],
            visualMetricKey: 'percent_of_total',
        );

        self::assertSame('percent_of_total', $query->resolvedVisualMetricKey());
    }

    public function testResolvedVisualMetricKeyFallsBackToFirstMetricWhenCountMissing(): void
    {
        $query = new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery(
            primaryDimensionKey: 'gender',
            scopeCriteria: GenericAnalysisTestFixtures::defaultQuery()->scopeCriteria,
            periodBounds: GenericAnalysisTestFixtures::defaultQuery()->periodBounds,
            metricKeys: ['percent_of_total'],
        );

        self::assertSame('percent_of_total', $query->resolvedVisualMetricKey());
    }

    public function testCompareModeInjectsPopulationGroupSeriesForHospitals(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery(
            dataSource: \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource::Hospitals,
            hospitalPopulationMode: \App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode::Compare,
        );

        $prepared = GenericAnalysisTestFixtures::modifierRegistry()->prepareForExecution($query);

        self::assertSame('hospital_population_group', $prepared->seriesDimensionKey);
    }
}
