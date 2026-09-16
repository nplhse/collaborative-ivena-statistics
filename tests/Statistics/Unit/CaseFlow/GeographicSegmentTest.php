<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileDimension;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentType;
use PHPUnit\Framework\TestCase;

final class GeographicSegmentTest extends TestCase
{
    public function testParsesOriginAndTravelQueryValues(): void
    {
        $origin = GeographicSegment::tryFromQueryValue('origin:15');
        $travel = GeographicSegment::tryFromQueryValue('travel:10_20');

        self::assertNotNull($origin);
        self::assertSame(GeographicSegmentType::OriginArea, $origin->type);
        self::assertSame(15, $origin->originDispatchAreaId());
        self::assertTrue($origin->isOriginArea());
        self::assertFalse($origin->isTravelTimeBand());
        self::assertSame('origin:15', $origin->toQueryValue());

        self::assertNotNull($travel);
        self::assertTrue($travel->isTravelTimeBand());
        self::assertSame('10_20', $travel->id);
        self::assertSame('travel:10_20', $travel->toQueryValue());
    }

    public function testRejectsInvalidQueryValues(): void
    {
        self::assertNull(GeographicSegment::tryFromQueryValue(null));
        self::assertNull(GeographicSegment::tryFromQueryValue(''));
        self::assertNull(GeographicSegment::tryFromQueryValue('origin:'));
        self::assertNull(GeographicSegment::tryFromQueryValue('origin:0'));
        self::assertNull(GeographicSegment::tryFromQueryValue('origin:abc'));
        self::assertNull(GeographicSegment::tryFromQueryValue('travel:30_45'));
        self::assertNull(GeographicSegment::tryFromQueryValue('hospital:1'));
    }

    public function testMapPayloadAndOriginIdForTravelBand(): void
    {
        $origin = GeographicSegment::originArea(8);
        $travel = GeographicSegment::travelTimeBand('under_10');

        self::assertSame(['type' => 'origin_area', 'id' => '8'], $origin->toMapPayload());
        self::assertSame(['type' => 'travel_time_band', 'id' => 'under_10'], $travel->toMapPayload());
        self::assertNull($travel->originDispatchAreaId());
        self::assertNull(GeographicSegment::originArea(0)->originDispatchAreaId());
    }

    public function testTravelSqlPredicatesCoverEachBandAndEmptyAlias(): void
    {
        [$underSql, $underParams] = GeographicSegment::travelTimeBand('under_10')->sqlPredicate('');
        self::assertSame('transport_time_minutes >= :geo_segment_travel_min AND transport_time_minutes < :geo_segment_travel_max', $underSql);
        self::assertSame(0, $underParams['geo_segment_travel_min']);
        self::assertSame(10, $underParams['geo_segment_travel_max']);

        [$sql20, $params20] = GeographicSegment::travelTimeBand('20_30')->sqlPredicate('asp');
        self::assertSame(20, $params20['geo_segment_travel_min']);
        self::assertSame(30, $params20['geo_segment_travel_max']);
        self::assertStringContainsString('asp.transport_time_minutes', $sql20);

        [$sql30, $params30] = GeographicSegment::travelTimeBand('30_40')->sqlPredicate();
        self::assertSame(30, $params30['geo_segment_travel_min']);
        self::assertSame(40, $params30['geo_segment_travel_max']);

        [$sql40, $params40] = GeographicSegment::travelTimeBand('40_50')->sqlPredicate();
        self::assertSame(40, $params40['geo_segment_travel_min']);
        self::assertSame(50, $params40['geo_segment_travel_max']);

        [$impossibleSql, $impossibleParams] = new GeographicSegment(GeographicSegmentType::TravelTimeBand, 'not_a_band')->sqlPredicate();
        self::assertSame('1 = 0', $impossibleSql);
        self::assertSame([], $impossibleParams);
    }

    public function testTravelLabelKeys(): void
    {
        self::assertNull(GeographicSegment::originArea(1)->labelTranslationKey());
        self::assertSame('stats.case_flow.segment.travel.beyond_max', GeographicSegment::travelTimeBand('beyond_max')->labelTranslationKey());
        self::assertSame('statistics.distribution.transport_time_bucket.unknown', GeographicSegment::travelTimeBand('unknown')->labelTranslationKey());
        self::assertSame('statistics.distribution.transport_time_bucket.10_20', GeographicSegment::travelTimeBand('10_20')->labelTranslationKey());
        self::assertSame(10, GeographicSegment::isochroneMinutesFromTravelBandId('under_10'));
        self::assertNull(GeographicSegment::isochroneMinutesFromTravelBandId('beyond_max'));
        self::assertSame(50, GeographicSegment::isochroneMinutesFromTravelBandId('40_50'));
        self::assertSame('40_50', GeographicSegment::travelBandIdFromIsochroneMinutes(50));
        self::assertSame('30_40', GeographicSegment::travelBandIdFromIsochroneMinutes(40));
    }

    public function testProfileDimensionTitleKeys(): void
    {
        self::assertSame('stats.case_flow.segment.tab.overview', GeographicSegmentProfileDimension::Overview->titleTranslationKey());
        self::assertSame('stats.case_flow.segment.tab.age', GeographicSegmentProfileDimension::Age->titleTranslationKey());
        self::assertSame('stats.case_flow.segment.tab.resources', GeographicSegmentProfileDimension::Resources->titleTranslationKey());
        self::assertSame('stats.case_flow.segment.tab.features', GeographicSegmentProfileDimension::Features->titleTranslationKey());
        self::assertSame(GeographicSegmentProfileDimension::Overview, GeographicSegmentProfileDimension::fromQueryValue('demographics'));
        self::assertSame(GeographicSegmentProfileDimension::Age, GeographicSegmentProfileDimension::fromQueryValue('age'));
    }

    public function testOriginSqlUsesDedicatedParameter(): void
    {
        [$sql, $params] = GeographicSegment::originArea(22)->sqlPredicate('asp');

        self::assertSame('asp.dispatch_area_id = :geo_segment_origin_id', $sql);
        self::assertSame(22, $params['geo_segment_origin_id']);
    }

    public function testTravelBandTenToTwentyIsHalfOpen(): void
    {
        [$sql, $params] = GeographicSegment::travelTimeBand('10_20')->sqlPredicate('asp');

        self::assertStringContainsString('>= :geo_segment_travel_min', $sql);
        self::assertStringContainsString('< :geo_segment_travel_max', $sql);
        self::assertSame(10, $params['geo_segment_travel_min']);
        self::assertSame(20, $params['geo_segment_travel_max']);
    }

    public function testUnknownAndBeyondMaxTravelPredicates(): void
    {
        [$unknownSql, $unknownParams] = GeographicSegment::travelTimeBand('unknown')->sqlPredicate('asp');
        [$beyondSql, $beyondParams] = GeographicSegment::travelTimeBand('beyond_max')->sqlPredicate('asp');

        self::assertSame('(asp.transport_time_minutes IS NULL OR asp.transport_time_minutes < 0)', $unknownSql);
        self::assertSame([], $unknownParams);
        self::assertSame('asp.transport_time_minutes >= :geo_segment_travel_min', $beyondSql);
        self::assertSame(50, $beyondParams['geo_segment_travel_min']);
    }

    public function testMapsIsochroneMinutesToTravelBandIds(): void
    {
        self::assertSame('under_10', GeographicSegment::travelBandIdFromIsochroneMinutes(10));
        self::assertSame('10_20', GeographicSegment::travelBandIdFromIsochroneMinutes(20));
        self::assertSame(20, GeographicSegment::isochroneMinutesFromTravelBandId('10_20'));
        self::assertNull(GeographicSegment::travelBandIdFromIsochroneMinutes(45));
    }

    public function testProfileDimensionFallsBackToOverview(): void
    {
        self::assertSame(GeographicSegmentProfileDimension::Overview, GeographicSegmentProfileDimension::fromQueryValue('urgency'));
        self::assertSame(GeographicSegmentProfileDimension::Overview, GeographicSegmentProfileDimension::fromQueryValue('gender'));
        self::assertSame(GeographicSegmentProfileDimension::Overview, GeographicSegmentProfileDimension::fromQueryValue('unknown'));
        self::assertSame(GeographicSegmentProfileDimension::Overview, GeographicSegmentProfileDimension::fromQueryValue(null));
    }
}
