<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCategoryCount;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentDistributionRow;
use PHPUnit\Framework\TestCase;

final class GeographicSegmentDistributionRowTest extends TestCase
{
    public function testFromCategoryRoundsDisplayAfterUnroundedDelta(): void
    {
        $row = GeographicSegmentDistributionRow::fromCategory(
            'label.urgency.emergency',
            new GeographicSegmentCategoryCount(2146, 1682),
            10_000,
            10_000,
            true,
            'bg-red',
        );

        self::assertSame('label.urgency.emergency', $row->labelTranslationKey);
        self::assertSame(2146, $row->count);
        self::assertSame(21.5, $row->segmentPercent);
        self::assertSame(16.8, $row->referencePercent);
        self::assertSame(4.6, $row->deltaPp);
        self::assertSame('bg-red', $row->barClass);
    }

    public function testFromCategoryOmitsReferenceWhenNotComparing(): void
    {
        $row = GeographicSegmentDistributionRow::fromCategory(
            'label.urgency.emergency',
            new GeographicSegmentCategoryCount(8, 8),
            12,
            12,
            false,
        );

        self::assertSame(8, $row->count);
        self::assertSame(66.7, $row->segmentPercent);
        self::assertNull($row->referencePercent);
        self::assertNull($row->deltaPp);
    }

    public function testFromCategoryKeepsZeroSegmentCountAgainstReference(): void
    {
        $row = GeographicSegmentDistributionRow::fromCategory(
            'label.urgency.inpatient',
            new GeographicSegmentCategoryCount(0, 5),
            10,
            20,
            true,
        );

        self::assertSame(0, $row->count);
        self::assertSame(0.0, $row->segmentPercent);
        self::assertSame(25.0, $row->referencePercent);
        self::assertSame(-25.0, $row->deltaPp);
    }

    public function testFromCategoryNullsReferenceWhenPopulationIsEmpty(): void
    {
        $row = GeographicSegmentDistributionRow::fromCategory(
            'label.urgency.emergency',
            GeographicSegmentCategoryCount::empty(),
            0,
            0,
            true,
        );

        self::assertSame(0, $row->count);
        self::assertSame(0.0, $row->segmentPercent);
        self::assertNull($row->referencePercent);
        self::assertNull($row->deltaPp);
    }
}
