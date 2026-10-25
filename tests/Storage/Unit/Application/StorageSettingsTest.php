<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application;

use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Enum\CapacitySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageSettingsTest extends TestCase
{
    public function testBuildsTypedSettings(): void
    {
        $settings = new StorageSettings(70, 85, 95, 10_737_418_240, ' Filesystem ', 24);

        self::assertSame(70, $settings->thresholds->noticePercent);
        self::assertSame(85, $settings->thresholds->warningPercent);
        self::assertSame(95, $settings->thresholds->criticalPercent);
        self::assertSame(10_737_418_240, $settings->budgetBytes);
        self::assertSame(CapacitySource::Filesystem, $settings->preferredCapacitySource);
        self::assertSame(24, $settings->measurementMaxAgeHours);
    }

    public function testTreatsAZeroBudgetAsNone(): void
    {
        self::assertNull(new StorageSettings(80, 90, 99, 0, 'budget', 36)->budgetBytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'none is derived, not configured' => ['none'];
        yield 'quota is not supported' => ['quota'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidSources')]
    public function testRejectsUnsupportedCapacitySources(string $source): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorageSettings(80, 90, 99, 0, $source, 36);
    }

    public function testRejectsAMaximumAgeBelowOneHour(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorageSettings(80, 90, 99, 0, 'budget', 0);
    }
}
