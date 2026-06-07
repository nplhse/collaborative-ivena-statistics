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

    public function testBuildSummaryDeckAndTimeSeries(): void
    {
        $metrics = $this->sampleMetrics();
        $deck = $this->assembler->buildSummaryDeck(
            ['male' => 60, 'female' => 30, 'other' => 5, 'unknown' => 5],
            $metrics,
        );
        $series = $this->assembler->buildTimeSeries([
            ['year' => 2026, 'month' => 3, 'count' => 12],
            ['year' => 2026, 'month' => 4, 'count' => 8],
        ]);

        self::assertSame(100, $deck->caseCount);
        self::assertCount(3, $deck->genderSegments);
        self::assertCount(3, $deck->urgencySegments);
        self::assertSame(['2026-03', '2026-04'], $series->labels);
        self::assertSame([12, 8], $series->values);
    }

    public function testBuildAgeHistogramAndGroupDistribution(): void
    {
        $histogram = $this->assembler->buildAgeHistogram([
            ['age' => 40, 'count' => 3],
            ['age' => 55, 'count' => 7],
        ]);
        $groups = $this->assembler->buildAgeGroupDistribution(['50_59' => 4, '80_89' => 1], 5);

        self::assertSame(['40', '55'], $histogram->labels);
        self::assertSame(80.0, $groups[4]->percent);
        self::assertSame(20.0, $groups[7]->percent);
        self::assertSame(0.0, $groups[0]->percent);
    }

    public function testBuildDistributionSections(): void
    {
        $metrics = $this->sampleMetrics();
        $gender = $this->assembler->buildGenderDistribution(
            ['male' => 50, 'female' => 40, 'other' => 5, 'unknown' => 5],
            100,
        );
        $urgency = $this->assembler->buildUrgencyDistribution($metrics);
        $transport = $this->assembler->buildTransportDistribution($metrics);
        $resources = $this->assembler->buildResourcesDistribution($metrics);
        $clinical = $this->assembler->buildClinicalFeatures($metrics);

        self::assertCount(4, $gender);
        self::assertSame(50.0, $gender[0]->percent);
        self::assertCount(3, $urgency);
        self::assertSame(20.0, $transport[1]->percent);
        self::assertSame(5, $resources[0]->count);
        self::assertSame(3, $clinical[0]->count);
    }

    public function testBuildHeatmaps(): void
    {
        $dayTime = $this->assembler->buildDayTimeHeatmap([
            ['weekday' => 1, 'dayTimeBucketCode' => 2, 'count' => 4],
        ]);
        $shift = $this->assembler->buildShiftHeatmap([
            ['weekday' => 7, 'shiftBucketCode' => 1, 'count' => 9],
        ]);

        self::assertSame(4, $dayTime->maxCount);
        self::assertSame(4, $dayTime->matrix[0][0]);
        self::assertSame(9, $shift->maxCount);
        self::assertSame(9, $shift->matrix[6][2]);
        self::assertContains('stats.indication.weekday.1', $dayTime->rowLabels);
    }

    private function sampleMetrics(): IndicationDashboardMetricsRow
    {
        return new IndicationDashboardMetricsRow(
            totalIndication: 100,
            totalBaseline: 0,
            withPhysicianIndication: 3,
            withPhysicianBaseline: 0,
            resusIndication: 5,
            resusBaseline: 0,
            cathlabIndication: 2,
            cathlabBaseline: 0,
            urgencyEmergencyIndication: 10,
            urgencyEmergencyBaseline: 0,
            infectiousIndication: 1,
            infectiousBaseline: 0,
            cprIndication: 2,
            cprBaseline: 0,
            ventilatedIndication: 3,
            ventilatedBaseline: 0,
            shockIndication: 1,
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
            groundTransportIndication: 80,
            groundTransportBaseline: 0,
            airTransportIndication: 20,
            airTransportBaseline: 0,
            urgencyInpatientIndication: 70,
            urgencyInpatientBaseline: 0,
            urgencyOutpatientIndication: 20,
            urgencyOutpatientBaseline: 0,
        );
    }
}
