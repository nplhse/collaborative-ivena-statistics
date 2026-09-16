<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GeographicMap;

use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandView;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\GeographicMap\Application\DTO\GeographicHospitalPin;
use App\Statistics\GeographicMap\Application\DTO\GeographicMapLayer;
use App\Statistics\GeographicMap\Application\GeographicMapPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class GeographicMapPayloadBuilderTest extends TestCase
{
    public function testRegionalPayloadIncludesDestinationLayerOnlyWhenExpanded(): void
    {
        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::SystemFlow,
            [new CaseFlowMapFeature(1, 'Frankfurt', 'frankfurt', 20, 100.0, false)],
            [new GeographicHospitalPin(4, 'Klinik', 50.1, 8.6, 20, 100.0, 3, 1, false)],
            null,
            false,
        );

        self::assertSame('regional', $payload['analysisLevel']);
        self::assertSame(['originChoropleth'], $payload['compactLayers']);
        self::assertContains('destinationHospitals', $payload['expandedLayers']);
        self::assertSame('frankfurt', $payload['mapFeatures'][0]['geoKey']);
        self::assertSame('Klinik', $payload['destinationHospitals'][0]['name']);
    }

    public function testDispatchAreaCompactViewIncludesDestinationPins(): void
    {
        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::SystemFlow,
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 20, 40.0, false)],
            [new GeographicHospitalPin(4, 'Klinik', 51.3, 9.5, 20, 40.0, 3, 1, false, false)],
            null,
            false,
            true,
            1,
            0,
            2,
        );

        self::assertContains('destinationHospitals', $payload['compactLayers']);
        self::assertSame(1, $payload['selectedDispatchAreaId']);
        self::assertFalse($payload['destinationHospitals'][0]['insideSelectedArea']);
        self::assertSame(2, $payload['omittedOutsideDestinationHospitals']);
    }

    public function testHospitalPayloadKeepsCatchmentChoroplethInCompactView(): void
    {
        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::HospitalOrigin,
            [new CaseFlowMapFeature(1, 'Frankfurt', 'frankfurt', 12, 100.0, false)],
            [],
            null,
            true,
        );

        self::assertSame('hospital', $payload['analysisLevel']);
        self::assertSame(['originChoropleth'], $payload['compactLayers']);
        self::assertContains('hospitalPin', $payload['expandedLayers']);
        self::assertNotContains('isochroneBands', $payload['layers']);
    }

    public function testPayloadForwardsOmittedDestinationHospitalCounts(): void
    {
        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::SystemFlow,
            [],
            [],
            null,
            false,
            true,
            15,
            1,
            2,
        );

        self::assertSame(15, $payload['selectedDispatchAreaId']);
        self::assertSame(1, $payload['omittedInsideDestinationHospitals']);
        self::assertSame(2, $payload['omittedOutsideDestinationHospitals']);
        self::assertContains('destinationHospitals', $payload['compactLayers']);
    }

    public function testPayloadForwardsSelectedGeographicSegment(): void
    {
        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::HospitalOrigin,
            [],
            [],
            null,
            true,
            false,
            null,
            0,
            0,
            ['type' => 'travel_time_band', 'id' => '10_20'],
            true,
        );

        self::assertSame(['type' => 'travel_time_band', 'id' => '10_20'], $payload['selectedSegment']);
        self::assertTrue($payload['segmentSelectionEnabled']);
    }

    public function testHospitalIsochroneCompactLayersIncludeBandsAndPin(): void
    {
        $isochrone = new IsochroneOriginHeatmapView(
            'Klinik',
            51.31,
            9.49,
            [new IsochroneOriginBandView(20, 12, 0.5, 1.0, ['type' => 'Polygon', 'coordinates' => []], '10–20 Min.')],
            0,
            0,
            12,
            12,
        );

        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::HospitalOrigin,
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 12, 100.0, false)],
            [],
            $isochrone,
            true,
            false,
            null,
            0,
            0,
            ['type' => 'origin_area', 'id' => '1'],
            true,
        );

        self::assertSame('hospital', $payload['analysisLevel']);
        self::assertSame(['originChoropleth', 'isochroneBands', 'hospitalPin'], $payload['compactLayers']);
        self::assertSame(['originChoropleth', 'hospitalPin', 'isochroneBands'], $payload['layers']);
        self::assertSame(51.31, $payload['hospital']['lat']);
        self::assertSame(['type' => 'origin_area', 'id' => '1'], $payload['selectedSegment']);
        self::assertCount(1, $payload['isochrone']['bands']);
    }

    public function testInsightsDefaultEnablesIsochronesWithoutOriginChoropleth(): void
    {
        $isochrone = new IsochroneOriginHeatmapView(
            'Klinik',
            51.31,
            9.49,
            [new IsochroneOriginBandView(20, 12, 0.5, 1.0, ['type' => 'Polygon', 'coordinates' => []], '10–20 Min.')],
            0,
            0,
            12,
            12,
        );

        $payload = new GeographicMapPayloadBuilder()->build(
            CaseFlowMode::HospitalOrigin,
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 12, 100.0, false)],
            [],
            $isochrone,
            true,
            compactEnabledLayers: [
                GeographicMapLayer::IsochroneBands,
                GeographicMapLayer::HospitalPin,
            ],
        );

        self::assertSame(['originChoropleth', 'isochroneBands', 'hospitalPin'], $payload['compactLayers']);
        self::assertSame(['isochroneBands', 'hospitalPin'], $payload['compactEnabledLayers']);
        self::assertSame(['isochroneBands', 'hospitalPin'], $payload['expandedLayers']);
    }
}
