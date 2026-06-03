<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Infrastructure\Repository;

use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KpiDailyRepositoryTest extends TestCase
{
    #[DataProvider('rejectionRateProvider')]
    public function testCalculateRejectionRate(int $rejected, int $total, float $expected): void
    {
        self::assertSame($expected, KpiDailyRepository::calculateRejectionRate($rejected, $total));
    }

    /**
     * @return iterable<string, array{int, int, float}>
     */
    public static function rejectionRateProvider(): iterable
    {
        yield 'zero total' => [0, 0, 0.0];
        yield 'ten percent' => [10, 100, 10.0];
        yield 'no rejections' => [0, 50, 0.0];
    }
}
