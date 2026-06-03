<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
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
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            metricKeys: ['count', 'percent_of_bucket'],
        );

        $this->expectException(IncompatibleAnalysisMetricException::class);
        $this->checker->resolveAndValidate($query);
    }

    public function testEmptyMetricKeysResolveToCount(): void
    {
        $definitions = $this->checker->resolveAndValidate(GenericAnalysisTestFixtures::defaultQuery());

        self::assertSame('count', $definitions[0]->key);
    }
}
