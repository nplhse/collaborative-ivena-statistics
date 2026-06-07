<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDistributionSlice;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowFlowMatrixRow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowKpiSet;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowOriginSlice;
use App\Statistics\CaseFlow\UI\Http\Controller\CaseFlowChartPayloadFactory;
use PHPUnit\Framework\TestCase;

final class CaseFlowChartPayloadFactoryTest extends TestCase
{
    private CaseFlowChartPayloadFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->factory = new CaseFlowChartPayloadFactory();
    }

    public function testCreateBuildsSystemFlowStackedBarPayload(): void
    {
        $fullTierKey = (string) AllocationStatsHospitalTierProjectionCode::Full->value;
        /** @var array<string, int> $destinationCounts */
        $destinationCounts = [
            $fullTierKey => 30,
            CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY => 10,
        ];

        $result = new CaseFlowDashboardResult(
            CaseFlowMode::SystemFlow,
            new CaseFlowKpiSet(55, 70.0, 45.0, 35.0, 'Frankfurt', 60.0, null, 20.0),
            [],
            [
                new CaseFlowOriginSlice(1, 'Frankfurt', 40, 10, false),
            ],
            [
                new CaseFlowFlowMatrixRow(1, 'Frankfurt', 40, $destinationCounts, false),
            ],
            [],
            [],
            [],
            null,
            null,
            null,
            [
                new CaseFlowDistributionSlice('20_30', 'stats.distribution.transport_time_bucket.20_30', 20, 36.4),
            ],
            [],
            [
                new CaseFlowMapFeature(1, 'Frankfurt', 'frankfurt', 40, 72.7, false),
            ],
            30.0,
            40.0,
        );

        $payload = $this->factory->create($result);

        self::assertSame('system_flow', $payload['mode']);
        self::assertSame(['Frankfurt'], $payload['flowStackedBar']['categories']);
        self::assertCount(4, $payload['flowStackedBar']['series']);
        self::assertSame(30, $payload['flowStackedBar']['series'][2]['data'][0]);
        self::assertSame('Frankfurt', $payload['originBar']['labels'][0]);
        self::assertSame(40, $payload['originBar']['values'][0]);
        self::assertSame(72.7, $payload['originBar']['percents'][0]);
        self::assertSame('frankfurt', $payload['mapFeatures'][0]['geoKey']);
        self::assertTrue(false === $payload['mapFeatures'][0]['suppressed']);
    }

    public function testCreateOmitsStackedBarForHospitalOriginMode(): void
    {
        $result = new CaseFlowDashboardResult(
            CaseFlowMode::HospitalOrigin,
            new CaseFlowKpiSet(12, 80.0, null, 25.0, 'Frankfurt', 100.0, 20.0, 10.0),
            [],
            [
                new CaseFlowOriginSlice(1, 'Frankfurt', 12, 2, false),
            ],
            [],
            [],
            [],
            [],
            null,
            null,
            null,
            [],
            [],
            [],
            null,
            null,
        );

        $payload = $this->factory->create($result);

        self::assertSame('hospital_origin', $payload['mode']);
        self::assertSame(['categories' => [], 'series' => []], $payload['flowStackedBar']);
        self::assertSame(12, $payload['originBar']['values'][0]);
    }
}
