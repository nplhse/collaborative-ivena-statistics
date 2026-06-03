<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisMetricException;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class MetricRegistryTest extends TestCase
{
    private MetricRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new MetricRegistry();
    }

    public function testRegistersProductionMetrics(): void
    {
        self::assertTrue($this->registry->has('count'));
        self::assertFalse($this->registry->has('mean_age'));
        self::assertFalse($this->registry->has('median_age'));
        self::assertTrue($this->registry->has('percent_of_total'));
        self::assertTrue($this->registry->has('percent_of_bucket'));
    }

    public function testCountSqlAliasIsWhitelisted(): void
    {
        $count = $this->registry->get('count');

        self::assertSame(MetricComputationKind::SqlAggregate, $count->computationKind);
        self::assertStringContainsString('COUNT(*)::INT AS count', $count->sqlSelectExpression ?? '');
    }

    public function testUnknownMetricThrows(): void
    {
        $this->expectException(UnknownAnalysisMetricException::class);
        $this->registry->get('evil_metric');
    }
}
