<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\View;

use App\Storage\Application\DTO\StorageBarSegment;
use App\Storage\Application\View\StorageSummaryBuilder;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StorageSummaryBuilderTest extends TestCase
{
    public function testSummarisesABudgetMeasurement(): void
    {
        $measurement = StorageViewFixtures::measurement(items: [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 200),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 100),
        ], level: CapacityLevel::Normal);

        $summary = $this->builder('2026-10-25 12:00')->build($measurement);

        self::assertSame(600, $summary->usedBytes);
        self::assertSame(1000, $summary->capacityBytes);
        self::assertSame(600, $summary->comparedUsedBytes);
        self::assertSame(400, $summary->remainingBytes);
        self::assertSame(60.0, $summary->usedPercent);
        self::assertSame(CapacitySource::Budget, $summary->capacitySource);
        self::assertSame(MeasurementMode::Deployer, $summary->mode);
        self::assertSame(1500, $summary->durationMs);
        self::assertFalse($summary->stale);
        self::assertSame(36, $summary->maxAgeHours);
        self::assertTrue($summary->hasCapacity());
        self::assertFalse($summary->isExceeded());
        self::assertSame('success', $summary->levelTextStyle());

        self::assertSame(
            ['database', 'imports', 'media', 'application', 'temporary', 'other'],
            array_map(static fn (StorageBarSegment $segment): string => $segment->key, $summary->segments),
        );
        self::assertSame([20.0, 30.0, 0.0, 10.0, 0.0, 0.0], array_map(static fn (StorageBarSegment $segment): float => $segment->widthPercent, $summary->segments));
        self::assertSame(StorageCategory::Imports->color(), $summary->segments[1]->color);
        self::assertSame('storage.category.imports', $summary->segments[1]->labelKey);
        self::assertSame(60.0, $summary->barUsedPercent());
    }

    public function testMarksAMeasurementOlderThanTheMaximumAgeAsStale(): void
    {
        $measurement = StorageViewFixtures::measurement();

        self::assertFalse($this->builder('2026-10-26 15:30')->build($measurement)->stale);
        self::assertTrue($this->builder('2026-10-26 15:31')->build($measurement)->stale);
    }

    public function testComparesTheFilesystemReferenceWithTheFilesystemUsage(): void
    {
        $measurement = StorageViewFixtures::measurement(
            items: [new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'source', 1000)],
            source: CapacitySource::Filesystem,
            capacity: 10_000,
            filesystemTotal: 10_000,
            filesystemFree: 2_000,
            level: CapacityLevel::Notice,
        );

        $summary = $this->builder()->build($measurement);

        self::assertSame(1000, $summary->usedBytes);
        self::assertSame(8000, $summary->comparedUsedBytes);
        self::assertSame(2000, $summary->remainingBytes);
        self::assertSame(80.0, $summary->usedPercent);
        self::assertSame('warning', $summary->levelTextStyle());

        $outside = $summary->segments[6];
        self::assertSame(StorageBarSegment::OUTSIDE_APPLICATION, $outside->key);
        self::assertSame(7000, $outside->bytes);
        self::assertSame(70.0, $outside->widthPercent);
        self::assertSame(10.0, $summary->segments[3]->widthPercent);
    }

    public function testScalesTheBarToTheUsageWhenTheCapacityIsExceeded(): void
    {
        $measurement = StorageViewFixtures::measurement(
            items: [
                new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 1500),
                new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Temporary, 'var_cache', 500),
            ],
            level: CapacityLevel::Exceeded,
        );

        $summary = $this->builder()->build($measurement);

        self::assertTrue($summary->isExceeded());
        self::assertSame(-1000, $summary->remainingBytes);
        self::assertSame(200.0, $summary->usedPercent);
        self::assertSame(75.0, $summary->segments[0]->widthPercent);
        self::assertSame(25.0, $summary->segments[4]->widthPercent);
        self::assertSame(100.0, $summary->barUsedPercent());
        self::assertSame('danger', $summary->levelTextStyle());
    }

    public function testWithoutReferenceOrUsageThereIsNoRemainingCapacity(): void
    {
        $measurement = StorageViewFixtures::measurement(
            items: [new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed)],
            used: null,
            source: CapacitySource::None,
            budget: null,
            level: CapacityLevel::Unknown,
        );

        $summary = $this->builder()->build($measurement);

        self::assertNull($summary->usedBytes);
        self::assertNull($summary->capacityBytes);
        self::assertNull($summary->remainingBytes);
        self::assertNull($summary->usedPercent);
        self::assertFalse($summary->hasCapacity());
        self::assertFalse($summary->isExceeded());
        self::assertSame('secondary', $summary->levelTextStyle());
        self::assertSame(0.0, $summary->barUsedPercent());
    }

    public function testFilesystemReferenceWithoutFilesystemValuesHasNoComparedUsage(): void
    {
        $measurement = StorageViewFixtures::measurement(source: CapacitySource::Filesystem, capacity: 10_000, filesystemTotal: null, filesystemFree: null);

        $summary = $this->builder()->build($measurement);

        self::assertNull($summary->comparedUsedBytes);
        self::assertNull($summary->remainingBytes);
        self::assertCount(6, $summary->segments);
    }

    /**
     * @return iterable<string, array{CapacityLevel, string}>
     */
    public static function levelStyles(): iterable
    {
        yield 'normal' => [CapacityLevel::Normal, 'success'];
        yield 'notice' => [CapacityLevel::Notice, 'warning'];
        yield 'warning' => [CapacityLevel::Warning, 'warning'];
        yield 'critical' => [CapacityLevel::Critical, 'danger'];
        yield 'exceeded' => [CapacityLevel::Exceeded, 'danger'];
    }

    #[DataProvider('levelStyles')]
    public function testMapsEveryLevelToAStyle(CapacityLevel $level, string $style): void
    {
        $summary = $this->builder()->build(StorageViewFixtures::measurement(used: 950, level: $level));

        self::assertSame($style, $summary->levelTextStyle());
    }

    private function builder(string $now = '2026-10-25 12:00'): StorageSummaryBuilder
    {
        return new StorageSummaryBuilder(
            StorageViewFixtures::settings(),
            new MockClock(new \DateTimeImmutable($now, new \DateTimeZone('Europe/Berlin'))),
        );
    }
}
