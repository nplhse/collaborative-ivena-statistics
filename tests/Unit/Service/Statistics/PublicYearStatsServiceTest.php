<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics;

use App\Model\PublicYearStatsView;
use App\Query\PublicYearStatsQuery;
use App\Service\Statistics\PublicYearStatsService;
use PHPUnit\Framework\TestCase;

final class PublicYearStatsServiceTest extends TestCase
{
    public function testReturnsNullIfQueryHasNoData(): void
    {
        $year = 2024;
        $query = $this->createMock(PublicYearStatsQuery::class);
        $query->expects(self::once())
            ->method('fetch')
            ->with($year)
            ->willReturn(null);

        $service = new PublicYearStatsService($query);

        self::assertNull($service->getYearViewModel($year));
    }

    public function testMapsAllFieldsAndCalculatesPercentages(): void
    {
        $year = 2024;

        $raw = $this->raw([
            'total' => 30,
            'computed_at' => '2024-12-31 23:59:59',

            'gender_m' => 10, // 33.3%
            'gender_w' => 15, // 50.0%
            'gender_d' => 5, // 16.7%

            'urg_1' => 12, // 40.0%
            'urg_2' => 9, // 30.0%
            'urg_3' => 9, // 30.0%

            'is_ventilated' => 3, // 10.0%
            'is_cpr' => 2, // 6.7%
            'is_shock' => 1, // 3.3%
            'with_physician' => 21, // 70.0%
            'is_pregnant' => 0, // 0.0%
            'infectious' => 4, // 13.3%
            'cathlab_required' => 8, // 26.7%
            'resus_required' => 7, // 23.3%
        ]);

        $query = $this->createMock(PublicYearStatsQuery::class);
        $query->expects(self::once())
            ->method('fetch')
            ->with($year)
            ->willReturn($raw);

        $service = new PublicYearStatsService($query);

        $vm = $service->getYearViewModel($year);
        self::assertInstanceOf(PublicYearStatsView::class, $vm);
        self::assertSame($year, $vm->year);
        self::assertSame(30, $vm->total);

        self::assertInstanceOf(\DateTimeImmutable::class, $vm->computedAt);
        self::assertSame('2024-12-31 23:59:59', $vm->computedAt->format('Y-m-d H:i:s'));

        self::assertSame(10, $vm->genderM);
        self::assertSame(15, $vm->genderW);
        self::assertSame(5, $vm->genderD);

        self::assertSame(12, $vm->urg1);
        self::assertSame(9, $vm->urg2);
        self::assertSame(9, $vm->urg3);

        self::assertSame(3, $vm->isVentilated);
        self::assertSame(2, $vm->isCpr);
        self::assertSame(1, $vm->isShock);
        self::assertSame(21, $vm->withPhysician);
        self::assertSame(0, $vm->isPregnant);
        self::assertSame(4, $vm->infectious);
        self::assertSame(8, $vm->cathlabRequired);
        self::assertSame(7, $vm->resusRequired);

        self::assertSame(33.3, $vm->malePct);
        self::assertSame(50.0, $vm->femalePct);
        self::assertSame(16.7, $vm->diversePct);

        self::assertSame(40.0, $vm->urg1Pct);
        self::assertSame(30.0, $vm->urg2Pct);
        self::assertSame(30.0, $vm->urg3Pct);

        self::assertSame(10.0, $vm->isVentilatedPct);
        self::assertSame(6.7, $vm->isCprPct);
        self::assertSame(3.3, $vm->isShockPct);
        self::assertSame(70.0, $vm->withPhysicianPct);
        self::assertSame(0.0, $vm->isPregnantPct);
        self::assertSame(13.3, $vm->infectiousPct);
        self::assertSame(26.7, $vm->cathlabRequiredPct);
        self::assertSame(23.3, $vm->resusRequiredPct);
    }

    public function testPercentagesAreZeroWhenTotalIsZero(): void
    {
        $year = 2024;

        $raw = $this->raw([
            'total' => 0,
            'computed_at' => '2024-01-01 00:00:00',
            'gender_m' => 0,
            'gender_w' => 0,
            'gender_d' => 0,
            'urg_1' => 0,
            'urg_2' => 0,
            'urg_3' => 0,
            'is_ventilated' => 0,
            'is_cpr' => 0,
            'is_shock' => 0,
            'with_physician' => 0,
            'is_pregnant' => 0,
            'infectious' => 0,
            'cathlab_required' => 0,
            'resus_required' => 0,
        ]);

        $query = $this->createMock(PublicYearStatsQuery::class);
        $query->method('fetch')->with($year)->willReturn($raw);

        $service = new PublicYearStatsService($query);
        $vm = $service->getYearViewModel($year);

        self::assertNotNull($vm);
        self::assertSame(0.0, $vm->malePct);
        self::assertSame(0.0, $vm->femalePct);
        self::assertSame(0.0, $vm->diversePct);

        self::assertSame(0.0, $vm->urg1Pct);
        self::assertSame(0.0, $vm->urg2Pct);
        self::assertSame(0.0, $vm->urg3Pct);

        self::assertSame(0.0, $vm->isVentilatedPct);
        self::assertSame(0.0, $vm->isCprPct);
        self::assertSame(0.0, $vm->isShockPct);
        self::assertSame(0.0, $vm->withPhysicianPct);
        self::assertSame(0.0, $vm->isPregnantPct);
        self::assertSame(0.0, $vm->infectiousPct);
        self::assertSame(0.0, $vm->cathlabRequiredPct);
        self::assertSame(0.0, $vm->resusRequiredPct);
    }

    /**
     * @param array<string,int|string> $overrides
     *
     * @return array<string,int|string>
     */
    private function raw(array $overrides = []): array
    {
        $base = [
            'total' => 0,
            'computed_at' => '1970-01-01 00:00:00',

            'gender_m' => 0,
            'gender_w' => 0,
            'gender_d' => 0,

            'urg_1' => 0,
            'urg_2' => 0,
            'urg_3' => 0,

            'is_ventilated' => 0,
            'is_cpr' => 0,
            'is_shock' => 0,
            'with_physician' => 0,
            'is_pregnant' => 0,
            'infectious' => 0,
            'cathlab_required' => 0,
            'resus_required' => 0,
        ];

        return array_replace($base, $overrides);
    }
}
