<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDispatchAreaFlow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDistributionSlice;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowFlowMatrixRow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowKpiSet;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowOriginSlice;
use App\Statistics\CaseFlow\UI\Http\Controller\CaseFlowChartPayloadFactory;
use App\Statistics\GeographicMap\Application\DTO\GeographicHospitalPin;
use App\Statistics\GeographicMap\Application\GeographicMapPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class CaseFlowChartPayloadFactoryTest extends TestCase
{
    private CaseFlowChartPayloadFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->factory = new CaseFlowChartPayloadFactory(new GeographicMapPayloadBuilder());
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
        self::assertSame(100.0, $payload['originBar']['percents'][0]);
        self::assertSame('frankfurt', $payload['mapFeatures'][0]['geoKey']);
        self::assertTrue(false === $payload['mapFeatures'][0]['suppressed']);
        self::assertSame('regional', $payload['geographicMap']['analysisLevel']);
        self::assertContains('originChoropleth', $payload['geographicMap']['layers']);
        self::assertContains('destinationHospitals', $payload['geographicMap']['expandedLayers']);
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
            null,
            null,
        );

        $payload = $this->factory->create($result);

        self::assertSame('hospital_origin', $payload['mode']);
        self::assertSame(['categories' => [], 'series' => []], $payload['flowStackedBar']);
        self::assertSame(12, $payload['originBar']['values'][0]);
    }

    public function testDispatchAreaFlowPutsDestinationPinsInCompactLayers(): void
    {
        $result = new CaseFlowDashboardResult(
            CaseFlowMode::SystemFlow,
            new CaseFlowKpiSet(14, 50.0, null, 20.0, 'Kassel', 50.0, 50.0, 10.0, 21.4, 28.6),
            [],
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            null,
            [],
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 7, 50.0, false)],
            null,
            null,
            [new GeographicHospitalPin(4, 'Klinik', 51.3, 9.5, 7, 50.0, 3, 1, false, false)],
            dispatchAreaFlow: new CaseFlowDispatchAreaFlow(3, 7, 4, 14, 21.4, 50.0, 28.6, 71.4, 78.6),
            selectedDispatchAreaId: 15,
            omittedOutsideDestinationHospitals: 2,
        );

        $payload = $this->factory->create($result);

        self::assertContains('destinationHospitals', $payload['geographicMap']['compactLayers']);
        self::assertSame(15, $payload['geographicMap']['selectedDispatchAreaId']);
        self::assertSame(2, $payload['geographicMap']['omittedOutsideDestinationHospitals']);
        self::assertFalse($payload['geographicMap']['destinationHospitals'][0]['insideSelectedArea']);
    }

    public function testHospitalFlowDoesNotEnableDispatchAreaCompactPins(): void
    {
        $result = new CaseFlowDashboardResult(
            CaseFlowMode::HospitalOrigin,
            new CaseFlowKpiSet(10, 70.0, null, 20.0, 'Kassel', 70.0, 30.0, 10.0, 30.0),
            [],
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            null,
            [],
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 7, 70.0, false)],
            null,
            null,
            singleHospital: true,
            hospitalFlow: new CaseFlowDispatchAreaFlow(3, 7, 0, 10, 30.0, 70.0, 0.0, 100.0, 70.0, false),
        );

        $payload = $this->factory->create($result);

        self::assertSame(['originChoropleth'], $payload['geographicMap']['compactLayers']);
        self::assertNotContains('destinationHospitals', $payload['geographicMap']['compactLayers']);
        self::assertNull($payload['geographicMap']['selectedDispatchAreaId']);
    }
}
