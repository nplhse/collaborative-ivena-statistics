<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
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

    public function testRegistersTransportMetrics(): void
    {
        foreach ([
            'mean_transport_time',
            'median_transport_time',
            'p25_transport_time',
            'p75_transport_time',
            'p90_transport_time',
        ] as $key) {
            self::assertTrue($this->registry->has($key), $key);
            $metric = $this->registry->get($key);
            self::assertSame(MetricComputationKind::SqlAggregate, $metric->computationKind);
            self::assertSame(MetricFormat::Minutes, $metric->defaultFormat);
            self::assertSame('transport_time_minutes', $metric->sourceColumn);
            self::assertStringContainsString('AS '.$key, $metric->sqlSelectExpression ?? '');
        }

        self::assertStringContainsString('AVG(transport_time_minutes)', $this->registry->get('mean_transport_time')->sqlSelectExpression ?? '');
        self::assertStringContainsString('PERCENTILE_CONT(0.5)', $this->registry->get('median_transport_time')->sqlSelectExpression ?? '');
    }

    public function testRegistersBooleanRateMetrics(): void
    {
        $rates = [
            'resus_rate' => 'requires_resus',
            'cpr_rate' => 'is_cpr',
            'shock_rate' => 'is_shock',
            'ventilation_rate' => 'is_ventilated',
            'cathlab_rate' => 'requires_cathlab',
            'pregnancy_rate' => 'is_pregnant',
            'work_accident_rate' => 'is_work_accident',
        ];

        foreach ($rates as $key => $column) {
            self::assertTrue($this->registry->has($key), $key);
            $metric = $this->registry->get($key);
            self::assertSame(MetricFormat::Percent, $metric->defaultFormat);
            self::assertSame($column, $metric->sourceColumn);
            self::assertStringContainsString(sprintf('FILTER (WHERE %s IS TRUE)', $column), $metric->sqlSelectExpression ?? '');
            self::assertStringContainsString('NULLIF(COUNT(*), 0)', $metric->sqlSelectExpression ?? '');
        }
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
