<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use PHPUnit\Framework\TestCase;

final class CatalogCoverageTest extends TestCase
{
    public function testSharePercentIsNullWhenSuppressedOrEmptyTotal(): void
    {
        $suppressed = new CatalogCoverage(3, 100, 0, 0, 0, null, null, [], true);
        self::assertNull($suppressed->sharePercent());

        $emptyTotal = new CatalogCoverage(10, 0, 1, 1, 1, null, null, [], false);
        self::assertNull($emptyTotal->sharePercent());
    }

    public function testSharePercentRoundsToTwoDecimals(): void
    {
        $coverage = new CatalogCoverage(1, 3, 1, 1, 1, null, null, [], false);

        self::assertSame(33.33, $coverage->sharePercent());
    }

    public function testEmptyFactory(): void
    {
        $coverage = CatalogCoverage::empty();

        self::assertFalse($coverage->hasData());
        self::assertSame(0, $coverage->allocationCount);
        self::assertFalse($coverage->suppressed);
    }
}
