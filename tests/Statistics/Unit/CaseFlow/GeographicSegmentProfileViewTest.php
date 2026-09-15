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
            GeographicSegmentProfileDimension::Urgency,
            [],
        );

        self::assertTrue($view->suppressed);
        self::assertNull($view->sharePercent);
        self::assertSame([], $view->groups);
        self::assertSame('Kassel', $view->label);
    }
}
