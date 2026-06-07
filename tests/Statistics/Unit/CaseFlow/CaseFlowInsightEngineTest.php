<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\CaseFlowInsightEngine;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowRegionalMetricsRow;
use PHPUnit\Framework\TestCase;

final class CaseFlowInsightEngineTest extends TestCase
{
    private CaseFlowInsightEngine $engine;

    #[\Override]
    protected function setUp(): void
    {
        $this->engine = new CaseFlowInsightEngine();
    }

    public function testBuildReturnsRegionalInsightForHighRegionalShare(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(200, 150, 80, 40, 35.0, 34.0);
        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            [],
            ['meanTransport' => 30.0, 'medianTransport' => 30.0, 'fullTierPercent' => 30.0],
        );

        self::assertNotEmpty($insights);
        self::assertSame('predominantly_regional', $insights[0]->id);
    }

    public function testBuildReturnsEmptyWhenBelowMinimumCases(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(10, 8, 2, 1, 20.0, 19.0);
        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            [],
            ['meanTransport' => 30.0, 'medianTransport' => 30.0, 'fullTierPercent' => 30.0],
        );

        self::assertSame([], $insights);
    }

    public function testBuildAddsUrgencyConcentrationInsight(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(200, 100, 50, 100, 30.0, 29.0);
        $origins = [
            new CaseFlowOriginRow(1, 'Frankfurt', 120, 50),
            new CaseFlowOriginRow(2, 'Kassel', 80, 10),
        ];

        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            $origins,
            ['meanTransport' => 30.0, 'medianTransport' => 30.0, 'fullTierPercent' => 20.0],
        );

        $ids = array_map(static fn (\App\Statistics\CaseFlow\Application\DTO\CaseFlowInsight $i): string => $i->id, $insights);
        self::assertContains('urgency_concentration_origin', $ids);
    }

    public function testBuildAddsCentralizationInsightWhenShareExceedsThreshold(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(200, 100, 120, 40, 35.0, 34.0);
        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            [],
            ['meanTransport' => 30.0, 'medianTransport' => 30.0, 'fullTierPercent' => 30.0],
        );

        $ids = array_map(static fn (\App\Statistics\CaseFlow\Application\DTO\CaseFlowInsight $i): string => $i->id, $insights);
        self::assertContains('high_centralization', $ids);
    }

    public function testBuildAddsTransportInsightWhenMeanExceedsBaseline(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(200, 100, 50, 40, 48.0, 45.0);
        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            [],
            ['meanTransport' => 30.0, 'medianTransport' => 28.0, 'fullTierPercent' => 20.0],
        );

        $ids = array_map(static fn (\App\Statistics\CaseFlow\Application\DTO\CaseFlowInsight $i): string => $i->id, $insights);
        self::assertContains('elevated_transport_time', $ids);
    }

    public function testBuildLimitsInsightsToFourCandidates(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(200, 160, 120, 100, 48.0, 45.0);
        $origins = [
            new CaseFlowOriginRow(1, 'Frankfurt', 120, 50),
        ];

        $insights = $this->engine->build(
            CaseFlowMode::SystemFlow,
            $metrics,
            $origins,
            ['meanTransport' => 30.0, 'medianTransport' => 28.0, 'fullTierPercent' => 30.0],
        );

        self::assertLessThanOrEqual(4, \count($insights));
    }

    public function testBuildUsesLowerMinimumForHospitalOriginMode(): void
    {
        $metrics = new CaseFlowRegionalMetricsRow(40, 30, 20, 10, 25.0, 24.0);
        $insights = $this->engine->build(
            CaseFlowMode::HospitalOrigin,
            $metrics,
            [],
            ['meanTransport' => 30.0, 'medianTransport' => 30.0, 'fullTierPercent' => 30.0],
        );

        self::assertNotEmpty($insights);
    }
}
