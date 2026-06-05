<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\IndicationDashboard;

use App\Statistics\Application\IndicationDashboard\DTO\IndicationInsight;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationInsightSeverity;
use App\Statistics\Application\IndicationDashboard\IndicationInsightEngine;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;
use PHPUnit\Framework\TestCase;

final class IndicationInsightEngineTest extends TestCase
{
    private IndicationInsightEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new IndicationInsightEngine();
    }

    public function testReturnsEmptyWhenSampleSizeTooSmall(): void
    {
        $metrics = $this->metricsRow(totalIndication: 10, totalBaseline: 50);

        self::assertSame([], $this->engine->build($metrics));
    }

    public function testPhysicianInsightWhenRatioHigh(): void
    {
        $metrics = $this->metricsRow(
            totalIndication: 100,
            totalBaseline: 500,
            withPhysicianIndication: 81,
            withPhysicianBaseline: 120,
        );

        $insights = $this->engine->build($metrics);
        self::assertNotEmpty($insights);
        self::assertSame('physician', $insights[0]->id);
        self::assertGreaterThanOrEqual(1.5, $insights[0]->ratio);
        self::assertSame(IndicationInsightSeverity::Critical, $insights[0]->severity);
    }

    public function testNightInsightIncludedInTopResults(): void
    {
        $metrics = $this->metricsRow(
            totalIndication: 80,
            totalBaseline: 400,
            nightDaytimeIndication: 40,
            nightDaytimeBaseline: 80,
        );

        $ids = array_map(static fn (IndicationInsight $i): string => $i->id, $this->engine->build($metrics));
        self::assertContains('night_daytime', $ids);
    }

    private function metricsRow(
        int $totalIndication = 0,
        int $totalBaseline = 0,
        int $withPhysicianIndication = 0,
        int $withPhysicianBaseline = 0,
        int $nightDaytimeIndication = 0,
        int $nightDaytimeBaseline = 0,
    ): IndicationDashboardMetricsRow {
        return new IndicationDashboardMetricsRow(
            totalIndication: $totalIndication,
            totalBaseline: $totalBaseline,
            withPhysicianIndication: $withPhysicianIndication,
            withPhysicianBaseline: $withPhysicianBaseline,
            resusIndication: 0,
            resusBaseline: 0,
            cathlabIndication: 0,
            cathlabBaseline: 0,
            urgencyEmergencyIndication: 0,
            urgencyEmergencyBaseline: 0,
            infectiousIndication: 0,
            infectiousBaseline: 0,
            cprIndication: 0,
            cprBaseline: 0,
            ventilatedIndication: 0,
            ventilatedBaseline: 0,
            shockIndication: 0,
            shockBaseline: 0,
            pregnantIndication: 0,
            pregnantBaseline: 0,
            workAccidentIndication: 0,
            workAccidentBaseline: 0,
            nightDaytimeIndication: $nightDaytimeIndication,
            nightDaytimeBaseline: $nightDaytimeBaseline,
            weekendIndication: 0,
            weekendBaseline: 0,
            age80PlusIndication: 0,
            age80PlusBaseline: 0,
            maleIndication: 0,
            maleBaseline: 0,
            femaleIndication: 0,
            femaleBaseline: 0,
            medianAgeIndication: null,
            medianAgeBaseline: null,
            medianTransportMinutesIndication: null,
            medianTransportMinutesBaseline: null,
            groundTransportIndication: 0,
            groundTransportBaseline: 0,
            airTransportIndication: 0,
            airTransportBaseline: 0,
            urgencyInpatientIndication: 0,
            urgencyInpatientBaseline: 0,
            urgencyOutpatientIndication: 0,
            urgencyOutpatientBaseline: 0,
        );
    }
}
