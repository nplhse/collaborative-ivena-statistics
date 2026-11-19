<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Application;

use App\Kpi\Application\DTO\KpiChangeDto;
use App\Kpi\Application\Service\KpiDashboardService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KpiChangeDtoTest extends TestCase
{
    /**
     * @return iterable<string, array{?float, bool, string}>
     */
    public static function tones(): iterable
    {
        yield 'more is better, up' => [2.0, false, 'success'];
        yield 'more is better, down' => [-2.0, false, 'danger'];
        yield 'lower is better, down' => [-0.5, true, 'success'];
        yield 'lower is better, up' => [0.5, true, 'danger'];
        yield 'no change' => [0.0, false, 'secondary'];
        yield 'no comparison' => [null, true, 'secondary'];
    }

    #[DataProvider('tones')]
    public function testToneFollowsTheDirectionThatIsBetter(?float $delta, bool $lowerIsBetter, string $tone): void
    {
        self::assertSame($tone, new KpiChangeDto($delta, KpiChangeDto::UNIT_COUNT, $lowerIsBetter)->tone());
    }

    public function testPercentChangeIsRoundedToOneDecimalAndMissingWithoutPreviousValue(): void
    {
        self::assertSame(50.0, KpiDashboardService::percentChange(300, 200));
        self::assertSame(-33.3, KpiDashboardService::percentChange(200, 300));
        self::assertSame(0.0, KpiDashboardService::percentChange(7, 7));
        self::assertNull(KpiDashboardService::percentChange(10, 0));
    }

    public function testPeriodFromQueryAcceptsOnlyTheOfferedPeriods(): void
    {
        self::assertSame(90, KpiDashboardService::periodFromQuery('90'));
        self::assertSame(365, KpiDashboardService::periodFromQuery('365'));
        self::assertSame(30, KpiDashboardService::periodFromQuery(''));
        self::assertSame(30, KpiDashboardService::periodFromQuery('7'));
        self::assertSame(30, KpiDashboardService::periodFromQuery('abc'));
    }
}
