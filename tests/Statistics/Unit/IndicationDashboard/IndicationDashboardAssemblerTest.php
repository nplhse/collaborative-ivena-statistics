<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\IndicationDashboard;

use App\Statistics\Application\IndicationDashboard\IndicationDashboardAssembler;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class IndicationDashboardAssemblerTest extends TestCase
{
    private IndicationDashboardAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new IndicationDashboardAssembler(new IdentityTranslator());
    }

    public function testBuildGenderSegments(): void
    {
        $segments = $this->assembler->buildGenderSegments(
            ['male' => 60, 'female' => 40, 'other' => 0, 'unknown' => 5],
            100,
        );

        self::assertCount(3, $segments);
        self::assertSame(60.0, $segments[0]->percent);
        self::assertSame('bg-primary', $segments[0]->barClass);
    }

    public function testBuildUrgencySegments(): void
    {
        $metrics = new IndicationDashboardMetricsRow(
            totalIndication: 100,
            totalBaseline: 0,
            withPhysicianIndication: 0,
            withPhysicianBaseline: 0,
            resusIndication: 0,
            resusBaseline: 0,
            cathlabIndication: 0,
            cathlabBaseline: 0,
            urgencyEmergencyIndication: 10,
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
            nightDaytimeIndication: 0,
            nightDaytimeBaseline: 0,
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
            urgencyInpatientIndication: 70,
            urgencyInpatientBaseline: 0,
            urgencyOutpatientIndication: 20,
            urgencyOutpatientBaseline: 0,
        );

        $segments = $this->assembler->buildUrgencySegments($metrics);

        self::assertCount(3, $segments);
        self::assertSame(70.0, $segments[1]->percent);
        self::assertSame('bg-yellow', $segments[1]->barClass);
    }

    public function testBuildTransportTimeDistribution(): void
    {
        $rows = $this->assembler->buildTransportTimeDistribution(
            [
                'under_10' => 5,
                '10_20' => 10,
                '20_30' => 15,
                'unknown' => 99,
            ],
            30,
        );

        self::assertCount(7, $rows);
        self::assertSame('statistics.distribution.transport_time_bucket.under_10', $rows[0]->labelTranslationKey);
        self::assertSame(5, $rows[0]->count);
        self::assertSame(16.7, $rows[0]->percent);
        self::assertSame('statistics.distribution.transport_time_bucket.over_60', $rows[6]->labelTranslationKey);
        self::assertSame(0, $rows[6]->count);
    }
}
