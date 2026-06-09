<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use PHPUnit\Framework\TestCase;

final class DescriptiveStatisticsCalculatorTest extends TestCase
{
    private DescriptiveStatisticsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DescriptiveStatisticsCalculator();
    }

    public function testCalculatesDescriptiveStats(): void
    {
        $stats = $this->calculator->calculate([10, 20, 30, 40, 50]);

        self::assertSame(5, $stats->count);
        self::assertSame(150, $stats->sum);
        self::assertSame(10, $stats->minimum);
        self::assertSame(50, $stats->maximum);
        self::assertSame(30.0, $stats->mean);
        self::assertSame(30.0, $stats->median);
        self::assertSame(14.0, $stats->p10);
        self::assertSame(20.0, $stats->p25);
        self::assertSame(40.0, $stats->p75);
        self::assertSame(48.0, $stats->p95);
        self::assertGreaterThan(0.0, $stats->standardDeviation);
    }

    public function testReturnsEmptyStatsForNoValues(): void
    {
        $stats = $this->calculator->calculate([]);

        self::assertSame(0, $stats->count);
        self::assertNull($stats->sum);
        self::assertNull($stats->mean);
    }
}
