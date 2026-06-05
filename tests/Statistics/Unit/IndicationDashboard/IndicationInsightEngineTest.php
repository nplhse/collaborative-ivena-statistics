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
            withPhysicianIndication: 81,
            withPhysicianBaseline: 120,
        );

        $insights = $this->engine->build($metrics);
        self::assertNotEmpty($insights);
        self::assertSame('physician', $insights[0]->id);
        self::assertGreaterThanOrEqual(1.5, $insights[0]->ratio);
        self::assertSame(IndicationInsightSeverity::Critical, $insights[0]->severity);
    }

    public function testPhysicianNeutralInsightWhenRatioBalanced(): void
    {
        $metrics = $this->metricsRow(
            withPhysicianIndication: 50,
            withPhysicianBaseline: 250,
        );

        $ids = $this->insightIds($this->engine->build($metrics));
        self::assertContains('physician_neutral', $ids);
    }

    public function testBuildsClinicalOperationalAndDemographicInsights(): void
    {
        $metrics = $this->metricsRow(
            withPhysicianIndication: 81,
            withPhysicianBaseline: 120,
            resusIndication: 20,
            resusBaseline: 30,
            cathlabIndication: 15,
            cathlabBaseline: 25,
            urgencyEmergencyIndication: 40,
            urgencyEmergencyBaseline: 80,
            infectiousIndication: 10,
            infectiousBaseline: 15,
            cprIndication: 8,
            cprBaseline: 10,
            ventilatedIndication: 12,
            ventilatedBaseline: 15,
            shockIndication: 10,
            shockBaseline: 12,
            nightDaytimeIndication: 40,
            nightDaytimeBaseline: 80,
            weekendIndication: 35,
            weekendBaseline: 70,
            age80PlusIndication: 30,
            age80PlusBaseline: 50,
            maleIndication: 70,
            maleBaseline: 200,
            femaleIndication: 30,
            femaleBaseline: 300,
            medianAgeIndication: 25.0,
            medianAgeBaseline: 40.0,
            medianTransportMinutesIndication: 45.0,
            medianTransportMinutesBaseline: 30.0,
        );

        $insights = $this->engine->build($metrics);
        $ids = $this->insightIds($insights);

        self::assertCount(6, $insights);
        self::assertContains('physician', $ids);
        self::assertContains('resus', $ids);
    }

    public function testBuildsGenderMaleDominantInsight(): void
    {
        $metrics = $this->metricsRow(
            maleIndication: 70,
            maleBaseline: 200,
            femaleIndication: 30,
            femaleBaseline: 300,
        );

        self::assertContains('gender_male_dominant', $this->insightIds($this->engine->build($metrics)));
    }

    public function testBuildsAge80PlusInsight(): void
    {
        $metrics = $this->metricsRow(
            age80PlusIndication: 30,
            age80PlusBaseline: 50,
        );

        self::assertContains('age_80_plus', $this->insightIds($this->engine->build($metrics)));
    }

    public function testBuildsFemaleDominantOldAgeAndShortTransportInsights(): void
    {
        $metrics = $this->metricsRow(
            maleIndication: 30,
            maleBaseline: 300,
            femaleIndication: 70,
            femaleBaseline: 200,
            medianAgeIndication: 72.0,
            medianAgeBaseline: 55.0,
            medianTransportMinutesIndication: 20.0,
            medianTransportMinutesBaseline: 40.0,
        );

        $ids = $this->insightIds($this->engine->build($metrics));

        self::assertContains('gender_female_dominant', $ids);
        self::assertContains('age_old', $ids);
        self::assertContains('transport_time_short', $ids);
    }

    public function testLimitsVisibleInsightsToSix(): void
    {
        $metrics = $this->metricsRow(
            withPhysicianIndication: 81,
            withPhysicianBaseline: 120,
            resusIndication: 20,
            resusBaseline: 30,
            cathlabIndication: 15,
            cathlabBaseline: 25,
            urgencyEmergencyIndication: 40,
            urgencyEmergencyBaseline: 80,
            nightDaytimeIndication: 40,
            nightDaytimeBaseline: 80,
            weekendIndication: 35,
            weekendBaseline: 70,
            age80PlusIndication: 30,
            age80PlusBaseline: 50,
        );

        self::assertCount(6, $this->engine->build($metrics));
    }

    public function testSkipsGenderInsightWhenKnownSampleTooSmall(): void
    {
        $metrics = $this->metricsRow(
            totalIndication: 30,
            maleIndication: 20,
            maleBaseline: 40,
            femaleIndication: 5,
            femaleBaseline: 5,
        );

        $ids = $this->insightIds($this->engine->build($metrics));
        self::assertNotContains('gender_male_dominant', $ids);
        self::assertNotContains('gender_female_dominant', $ids);
    }

    /**
     * @param list<IndicationInsight> $insights
     *
     * @return list<string>
     */
    private function insightIds(array $insights): array
    {
        return array_map(static fn (IndicationInsight $insight): string => $insight->id, $insights);
    }

    private function metricsRow(
        int $totalIndication = 100,
        int $totalBaseline = 500,
        int $withPhysicianIndication = 0,
        int $withPhysicianBaseline = 0,
        int $resusIndication = 0,
        int $resusBaseline = 0,
        int $cathlabIndication = 0,
        int $cathlabBaseline = 0,
        int $urgencyEmergencyIndication = 0,
        int $urgencyEmergencyBaseline = 0,
        int $infectiousIndication = 0,
        int $infectiousBaseline = 0,
        int $cprIndication = 0,
        int $cprBaseline = 0,
        int $ventilatedIndication = 0,
        int $ventilatedBaseline = 0,
        int $shockIndication = 0,
        int $shockBaseline = 0,
        int $pregnantIndication = 0,
        int $pregnantBaseline = 0,
        int $workAccidentIndication = 0,
        int $workAccidentBaseline = 0,
        int $nightDaytimeIndication = 0,
        int $nightDaytimeBaseline = 0,
        int $weekendIndication = 0,
        int $weekendBaseline = 0,
        int $age80PlusIndication = 0,
        int $age80PlusBaseline = 0,
        int $maleIndication = 0,
        int $maleBaseline = 0,
        int $femaleIndication = 0,
        int $femaleBaseline = 0,
        ?float $medianAgeIndication = null,
        ?float $medianAgeBaseline = null,
        ?float $medianTransportMinutesIndication = null,
        ?float $medianTransportMinutesBaseline = null,
        int $groundTransportIndication = 0,
        int $groundTransportBaseline = 0,
        int $airTransportIndication = 0,
        int $airTransportBaseline = 0,
        int $urgencyInpatientIndication = 0,
        int $urgencyInpatientBaseline = 0,
        int $urgencyOutpatientIndication = 0,
        int $urgencyOutpatientBaseline = 0,
    ): IndicationDashboardMetricsRow {
        return new IndicationDashboardMetricsRow(
            totalIndication: $totalIndication,
            totalBaseline: $totalBaseline,
            withPhysicianIndication: $withPhysicianIndication,
            withPhysicianBaseline: $withPhysicianBaseline,
            resusIndication: $resusIndication,
            resusBaseline: $resusBaseline,
            cathlabIndication: $cathlabIndication,
            cathlabBaseline: $cathlabBaseline,
            urgencyEmergencyIndication: $urgencyEmergencyIndication,
            urgencyEmergencyBaseline: $urgencyEmergencyBaseline,
            infectiousIndication: $infectiousIndication,
            infectiousBaseline: $infectiousBaseline,
            cprIndication: $cprIndication,
            cprBaseline: $cprBaseline,
            ventilatedIndication: $ventilatedIndication,
            ventilatedBaseline: $ventilatedBaseline,
            shockIndication: $shockIndication,
            shockBaseline: $shockBaseline,
            pregnantIndication: $pregnantIndication,
            pregnantBaseline: $pregnantBaseline,
            workAccidentIndication: $workAccidentIndication,
            workAccidentBaseline: $workAccidentBaseline,
            nightDaytimeIndication: $nightDaytimeIndication,
            nightDaytimeBaseline: $nightDaytimeBaseline,
            weekendIndication: $weekendIndication,
            weekendBaseline: $weekendBaseline,
            age80PlusIndication: $age80PlusIndication,
            age80PlusBaseline: $age80PlusBaseline,
            maleIndication: $maleIndication,
            maleBaseline: $maleBaseline,
            femaleIndication: $femaleIndication,
            femaleBaseline: $femaleBaseline,
            medianAgeIndication: $medianAgeIndication,
            medianAgeBaseline: $medianAgeBaseline,
            medianTransportMinutesIndication: $medianTransportMinutesIndication,
            medianTransportMinutesBaseline: $medianTransportMinutesBaseline,
            groundTransportIndication: $groundTransportIndication,
            groundTransportBaseline: $groundTransportBaseline,
            airTransportIndication: $airTransportIndication,
            airTransportBaseline: $airTransportBaseline,
            urgencyInpatientIndication: $urgencyInpatientIndication,
            urgencyInpatientBaseline: $urgencyInpatientBaseline,
            urgencyOutpatientIndication: $urgencyOutpatientIndication,
            urgencyOutpatientBaseline: $urgencyOutpatientBaseline,
        );
    }
}
