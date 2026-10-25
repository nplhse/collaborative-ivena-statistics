<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Capacity;

use App\Storage\Application\Capacity\CapacityThresholds;
use App\Storage\Domain\Enum\CapacityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapacityThresholdsTest extends TestCase
{
    /**
     * @return iterable<string, array{int|null, int|null, CapacityLevel}>
     */
    public static function usages(): iterable
    {
        yield 'empty' => [0, 10_000, CapacityLevel::Normal];
        yield 'just below notice' => [7_999, 10_000, CapacityLevel::Normal];
        yield 'notice starts at 80 %' => [8_000, 10_000, CapacityLevel::Notice];
        yield 'just below warning' => [8_999, 10_000, CapacityLevel::Notice];
        yield 'warning starts at 90 %' => [9_000, 10_000, CapacityLevel::Warning];
        yield 'just below critical' => [9_899, 10_000, CapacityLevel::Warning];
        yield 'critical starts at 99 %' => [9_900, 10_000, CapacityLevel::Critical];
        yield 'just below the capacity' => [9_999, 10_000, CapacityLevel::Critical];
        yield 'exceeded at 100 %' => [10_000, 10_000, CapacityLevel::Exceeded];
        yield 'above the capacity' => [25_000, 10_000, CapacityLevel::Exceeded];
        yield 'large values' => [9 * 1024 ** 4, 10 * 1024 ** 4, CapacityLevel::Warning];
        yield 'no usage' => [null, 10_000, CapacityLevel::Unknown];
        yield 'no capacity' => [5_000, null, CapacityLevel::Unknown];
        yield 'zero capacity' => [5_000, 0, CapacityLevel::Unknown];
    }

    #[DataProvider('usages')]
    public function testMapsUsageToLevel(?int $used, ?int $capacity, CapacityLevel $expected): void
    {
        self::assertSame($expected, new CapacityThresholds()->levelFor($used, $capacity));
    }

    public function testUsesConfiguredThresholds(): void
    {
        $thresholds = new CapacityThresholds(50, 60, 70);

        self::assertSame(CapacityLevel::Notice, $thresholds->levelFor(55, 100));
        self::assertSame(CapacityLevel::Critical, $thresholds->levelFor(75, 100));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'zero notice' => [0, 90, 99];
        yield 'equal notice and warning' => [90, 90, 99];
        yield 'warning above critical' => [80, 99, 95];
        yield 'critical at 100' => [80, 90, 100];
    }

    #[DataProvider('invalidThresholds')]
    public function testRejectsThresholdsThatDoNotIncrease(int $notice, int $warning, int $critical): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CapacityThresholds($notice, $warning, $critical);
    }
}
