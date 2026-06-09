<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Statistics\HospitalPopulation\Application\CoverageCalculator;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageRow;
use App\Statistics\HospitalPopulation\Application\RepresentativityCalculator;
use PHPUnit\Framework\TestCase;

final class RepresentativityCalculatorTest extends TestCase
{
    public function testCalculatesDeltaInPercentPoints(): void
    {
        $calculator = new RepresentativityCalculator(new CoverageCalculator());
        $rows = [
            new CoverageRow('Large', 'Large', 80, 40, 0.5),
            new CoverageRow('Small', 'Small', 20, 10, 0.5),
        ];

        $result = $calculator->fromCoverageRows($rows, 100, 50);

        self::assertSame(80.0, $result[0]->populationSharePercent);
        self::assertSame(80.0, $result[0]->participantSharePercent);
        self::assertSame(0.0, $result[0]->deltaPercentPoints);
        self::assertSame(20.0, $result[1]->populationSharePercent);
        self::assertSame(20.0, $result[1]->participantSharePercent);
    }
}
