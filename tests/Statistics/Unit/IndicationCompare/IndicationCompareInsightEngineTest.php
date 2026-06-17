<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\IndicationCompare;

use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareInsight;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareInsightSeverity;
use App\Statistics\Application\IndicationCompare\IndicationCompareInsightEngine;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareSideCounts;
use PHPUnit\Framework\TestCase;

final class IndicationCompareInsightEngineTest extends TestCase
{
    private IndicationCompareInsightEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new IndicationCompareInsightEngine();
    }

    public function testReturnsEmptyWhenSampleSizeTooSmall(): void
    {
        self::assertSame([], $this->engine->build(
            $this->sideCounts(total: 20),
            $this->sideCounts(total: 50),
        ));
    }

    public function testPhysicianNeutralInsightWhenRatesBalanced(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, withPhysician: 50),
            $this->sideCounts(total: 100, withPhysician: 50),
        ));

        self::assertContains('physician_neutral', $ids);
        self::assertNotContains('physician', $ids);
        self::assertLessThanOrEqual(2, \count($ids));
    }

    public function testPhysicianInsightWhenRatioHigh(): void
    {
        $insights = $this->engine->build(
            $this->sideCounts(total: 100, withPhysician: 81),
            $this->sideCounts(total: 100, withPhysician: 40),
        );

        self::assertNotEmpty($insights);
        self::assertSame('physician', $insights[0]->id);
        self::assertGreaterThanOrEqual(1.5, $insights[0]->ratio);
        self::assertSame(IndicationCompareInsightSeverity::Critical, $insights[0]->severity);
    }

    public function testDoesNotEmitLowDirectionRateInsights(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, withPhysician: 10),
            $this->sideCounts(total: 100, withPhysician: 40),
        ));

        self::assertNotContains('physician_low', $ids);
        self::assertNotContains('physician', $ids);
    }

    public function testSuppressesFloodingWhenIndicationAMuchLower(): void
    {
        $insights = $this->engine->build(
            $this->sideCounts(
                total: 100,
                withPhysician: 9,
                resus: 1,
                cathlab: 0,
                urgencyEmergency: 2,
                cpr: 0,
                ventilated: 0,
            ),
            $this->sideCounts(
                total: 100,
                withPhysician: 96,
                resus: 78,
                cathlab: 43,
                urgencyEmergency: 100,
                cpr: 94,
                ventilated: 97,
            ),
        );

        self::assertSame([], $insights);
    }

    public function testSuppressesMarginalRateDifferenceBelowThreePercentagePoints(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, resus: 13),
            $this->sideCounts(total: 100, resus: 10),
        ));

        self::assertNotContains('resus', $ids);
        self::assertNotContains('resus_low', $ids);
    }

    public function testDoesNotFillSixInsightsForSimilarProfiles(): void
    {
        $insights = $this->engine->build(
            $this->sideCounts(
                total: 100,
                withPhysician: 50,
                resus: 20,
                cathlab: 10,
                urgencyEmergency: 30,
                nightDaytime: 25,
                weekend: 20,
                age80Plus: 15,
            ),
            $this->sideCounts(
                total: 100,
                withPhysician: 50,
                resus: 20,
                cathlab: 10,
                urgencyEmergency: 30,
                nightDaytime: 25,
                weekend: 20,
                age80Plus: 15,
            ),
        );

        self::assertLessThanOrEqual(2, \count($insights));
    }

    public function testEmitsAgeYoungInsightWhenMedianAgeMuchLower(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, medianAge: 30.0),
            $this->sideCounts(total: 100, medianAge: 50.0),
        ));

        self::assertContains('age_young', $ids);
        self::assertNotContains('age_old', $ids);
    }

    public function testEmitsAgeOldInsightWhenMedianAgeMuchHigher(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, medianAge: 72.0),
            $this->sideCounts(total: 100, medianAge: 50.0),
        ));

        self::assertContains('age_old', $ids);
        self::assertNotContains('age_young', $ids);
    }

    public function testEmitsTransportTimeLongInsightWhenMedianMuchHigher(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, medianTransportMinutes: 36.0),
            $this->sideCounts(total: 100, medianTransportMinutes: 15.0),
        ));

        self::assertContains('transport_time_long', $ids);
        self::assertNotContains('transport_time_short', $ids);
    }

    public function testEmitsTransportTimeShortInsightWhenMedianMuchLower(): void
    {
        $ids = $this->insightIds($this->engine->build(
            $this->sideCounts(total: 100, medianTransportMinutes: 12.0),
            $this->sideCounts(total: 100, medianTransportMinutes: 30.0),
        ));

        self::assertContains('transport_time_short', $ids);
        self::assertNotContains('transport_time_long', $ids);
    }

    /**
     * @param list<IndicationCompareInsight> $insights
     *
     * @return list<string>
     */
    private function insightIds(array $insights): array
    {
        return array_map(static fn (IndicationCompareInsight $insight): string => $insight->id, $insights);
    }

    private function sideCounts(
        int $total = 100,
        int $withPhysician = 0,
        int $resus = 0,
        int $cathlab = 0,
        int $urgencyEmergency = 0,
        int $nightDaytime = 0,
        int $weekend = 0,
        int $age80Plus = 0,
        int $cpr = 0,
        int $ventilated = 0,
        int $shock = 0,
        int $infectious = 0,
        ?float $medianAge = null,
        ?float $medianTransportMinutes = null,
    ): IndicationCompareSideCounts {
        return new IndicationCompareSideCounts(
            $total,
            $withPhysician,
            $resus,
            $cathlab,
            $cpr,
            $ventilated,
            $shock,
            0,
            0,
            $infectious,
            $urgencyEmergency,
            0,
            0,
            $nightDaytime,
            $weekend,
            $age80Plus,
            0,
            0,
            0,
            0,
            0,
            $medianAge,
            $medianTransportMinutes,
        );
    }
}
