<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Exception\IncompatibleAnalysisMetricException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class MetricCompatibilityCheckerTest extends TestCase
{
    private MetricCompatibilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new MetricCompatibilityChecker(new MetricRegistry(), new DimensionRegistry());
    }

    public function testCountAlwaysAllowed(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery('month');
        $primary = new DimensionRegistry()->get('month');

        $result = $this->checker->check($query, $primary, null, new MetricRegistry()->get('count'));

        self::assertTrue($result->allowed);
    }

    public function testPercentOfBucketRequiresSeries(): void
    {
        $query = new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: GenericAnalysisTestFixtures::defaultQuery()->scopeCriteria,
            periodBounds: GenericAnalysisTestFixtures::defaultQuery()->periodBounds,
            metricKeys: ['count', 'percent_of_bucket'],
        );

        $this->expectException(IncompatibleAnalysisMetricException::class);
        $this->checker->resolveAndValidate($query);
    }

    public function testTransportMetricsAreAllowed(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery(
            'department',
            metricKeys: ['count', 'median_transport_time', 'p90_transport_time'],
        );

        $definitions = $this->checker->resolveAndValidate($query);

        self::assertCount(3, $definitions);
        self::assertSame('median_transport_time', $definitions[1]->key);
    }

    public function testRateMetricsAreAllowed(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery(
            'department',
            metricKeys: ['count', 'resus_rate'],
        );

        $definitions = $this->checker->resolveAndValidate($query);

        self::assertCount(2, $definitions);
        self::assertSame('resus_rate', $definitions[1]->key);
    }

    public function testEmptyMetricKeysResolveToCount(): void
    {
        $definitions = $this->checker->resolveAndValidate(GenericAnalysisTestFixtures::defaultQuery());

        self::assertSame('count', $definitions[0]->key);
    }
}
