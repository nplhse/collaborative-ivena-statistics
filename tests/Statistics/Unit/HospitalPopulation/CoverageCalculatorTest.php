<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Statistics\HospitalPopulation\Application\CoverageCalculator;
use PHPUnit\Framework\TestCase;

final class CoverageCalculatorTest extends TestCase
{
    private CoverageCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CoverageCalculator();
    }

    public function testCalculateReturnsRatio(): void
    {
        self::assertSame(0.5, $this->calculator->calculate(5, 10));
    }

    public function testCalculateReturnsZeroForEmptyPopulation(): void
    {
        self::assertSame(0.0, $this->calculator->calculate(3, 0));
    }

    public function testCalculatePercent(): void
    {
        self::assertSame(25.0, $this->calculator->calculatePercent(1, 4));
    }
}
