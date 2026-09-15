<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandView;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowKpiSet;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCatalogFactory;
use App\Statistics\CaseFlow\Infrastructure\Query\GeographicSegmentSql;
use PHPUnit\Framework\TestCase;

final class GeographicSegmentCatalogFactoryTest extends TestCase
{
    public function testPickerOmitsSuppressedOriginsAndAddsTravelBandsForHospital(): void
    {
        $catalog = new GeographicSegmentCatalogFactory()->fromDashboardResult(new CaseFlowDashboardResult(
            CaseFlowMode::HospitalOrigin,
            new CaseFlowKpiSet(24, 100.0, null, 20.0, 'Kassel', 100.0, 0.0, 10.0),
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            null,
            [],
            [
                new CaseFlowMapFeature(1, 'Kassel', 'kassel', 14, 58.3, false),
                new CaseFlowMapFeature(2, 'Marburg', 'marburg', 4, 0.0, true),
            ],
            null,
            null,
            isochrone: new IsochroneOriginHeatmapView(
                'Klinik',
                51.3,
                9.5,
                [
                    new IsochroneOriginBandView(20, 12, 0.5, 1.0, ['type' => 'Polygon', 'coordinates' => []], '10–20 Min.'),
                    new IsochroneOriginBandView(30, 0, 0.0, 0.0, ['type' => 'Polygon', 'coordinates' => []], '20–30 Min.'),
                ],
                11,
                2,
                24,
                12,
            ),
            singleHospital: true,
        ));

        $pickerValues = array_map(
            static fn (\App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentOption $option): string => $option->segment->toQueryValue(),
            $catalog->pickerOptions(),
        );

        self::assertContains('origin:1', $pickerValues);
        self::assertNotContains('origin:2', $pickerValues);
        self::assertContains('travel:10_20', $pickerValues);
        self::assertNotContains('travel:20_30', $pickerValues);
        self::assertContains('travel:unknown', $pickerValues);
        self::assertContains('travel:beyond_max', $pickerValues);
        self::assertSame(['origin:1'], array_map(
            static fn (\App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentOption $option): string => $option->segment->toQueryValue(),
            $catalog->pickerOriginOptions(),
        ));
        self::assertContains('travel:10_20', array_map(
            static fn (\App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentOption $option): string => $option->segment->toQueryValue(),
            $catalog->pickerTravelOptions(),
        ));

        $beyond = $catalog->find(GeographicSegment::travelTimeBand('beyond_max'));
        self::assertNotNull($beyond);
        self::assertTrue($beyond->suppressed);
        self::assertTrue($beyond->showInPicker);
        self::assertNull($catalog->find(GeographicSegment::originArea(99)));
    }

    public function testUnknownTravelBandBelowThresholdIsOmittedAndUnmappedMinutesAreSkipped(): void
    {
        $catalog = new GeographicSegmentCatalogFactory()->fromMapFeatures(
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 14, 100.0, false)],
            new IsochroneOriginHeatmapView(
                'Klinik',
                51.3,
                9.5,
                [
                    new IsochroneOriginBandView(15, 20, 1.0, 1.0, ['type' => 'Polygon', 'coordinates' => []], 'odd'),
                    new IsochroneOriginBandView(10, 11, 0.5, 0.5, ['type' => 'Polygon', 'coordinates' => []], '0–10 Min.'),
                ],
                4,
                0,
                15,
                11,
            ),
            true,
        );

        $pickerValues = array_map(
            static fn (\App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentOption $option): string => $option->segment->toQueryValue(),
            $catalog->pickerOptions(),
        );

        self::assertContains('origin:1', $pickerValues);
        self::assertContains('travel:under_10', $pickerValues);
        self::assertNotContains('travel:unknown', $pickerValues);
        self::assertNotContains('travel:beyond_max', $pickerValues);
    }

    public function testDispatchAreaCatalogOmitsTravelBandsEvenWhenIsochroneIsPresent(): void
    {
        $catalog = new GeographicSegmentCatalogFactory()->fromMapFeatures(
            [new CaseFlowMapFeature(1, 'Kassel', 'kassel', 14, 100.0, false)],
            new IsochroneOriginHeatmapView(
                'Klinik',
                51.3,
                9.5,
                [new IsochroneOriginBandView(20, 12, 0.5, 1.0, ['type' => 'Polygon', 'coordinates' => []], '10–20 Min.')],
                0,
                0,
                12,
                12,
            ),
            false,
        );

        $pickerValues = array_map(
            static fn (\App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentOption $option): string => $option->segment->toQueryValue(),
            $catalog->pickerOptions(),
        );

        self::assertSame(['origin:1'], $pickerValues);
        self::assertSame([], $catalog->pickerTravelOptions());
    }

    public function testSqlAppendAddsOriginPredicate(): void
    {
        [$where, $params, $types] = GeographicSegmentSql::append(
            '1 = 1',
            [],
            [],
            GeographicSegment::originArea(9),
        );

        self::assertSame('1 = 1 AND asp.dispatch_area_id = :geo_segment_origin_id', $where);
        self::assertSame(9, $params['geo_segment_origin_id']);
        self::assertSame([], $types);

        [$unchanged, $unchangedParams, $unchangedTypes] = GeographicSegmentSql::append('1 = 1', [], [], null);
        self::assertSame('1 = 1', $unchanged);
        self::assertSame([], $unchangedParams);
        self::assertSame([], $unchangedTypes);

        [$travelWhere, $travelParams] = GeographicSegmentSql::append(
            'asp.hospital_id = :hid',
            ['hid' => 3],
            [],
            GeographicSegment::travelTimeBand('10_20'),
            'asp',
        );
        self::assertStringContainsString('AND asp.transport_time_minutes >= :geo_segment_travel_min', $travelWhere);
        self::assertSame(3, $travelParams['hid']);
        self::assertSame(10, $travelParams['geo_segment_travel_min']);
        self::assertSame(20, $travelParams['geo_segment_travel_max']);
    }
}
