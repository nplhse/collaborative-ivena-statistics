<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileDimension;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentProfileView;
use PHPUnit\Framework\TestCase;

final class GeographicSegmentProfileViewTest extends TestCase
{
    public function testEmptySelectionHasNoMetrics(): void
    {
        $view = GeographicSegmentProfileView::empty(GeographicSegmentProfileDimension::Overview);

        self::assertFalse($view->hasSelection);
        self::assertFalse($view->suppressed);
        self::assertSame([], $view->groups);
        self::assertNull($view->segment);
        self::assertFalse($view->showsReferenceComparison());
    }

    public function testSuppressedViewHidesShareAndDimensionRows(): void
    {
        $view = new GeographicSegmentProfileView(
            GeographicSegment::originArea(4),
            'Kassel',
            false,
            true,
            true,
            4,
            40,
            null,
            null,
            false,
            GeographicSegmentProfileDimension::Overview,
            [],
        );

        self::assertTrue($view->suppressed);
        self::assertNull($view->sharePercent);
        self::assertSame([], $view->groups);
        self::assertSame('Kassel', $view->label);
        self::assertFalse($view->showsReferenceComparison());
    }

    public function testSelectedSegmentShowsReferenceComparison(): void
    {
        $view = new GeographicSegmentProfileView(
            GeographicSegment::travelTimeBand('10_20'),
            'statistics.distribution.transport_time_bucket.10_20',
            true,
            true,
            false,
            12,
            20,
            60.0,
            null,
            false,
            GeographicSegmentProfileDimension::Overview,
            [],
        );

        self::assertTrue($view->showsReferenceComparison());
    }
}
