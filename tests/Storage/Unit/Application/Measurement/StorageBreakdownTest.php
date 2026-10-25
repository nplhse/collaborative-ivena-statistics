<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Measurement;

use App\Storage\Application\ByteFormatter;
use App\Storage\Application\Measurement\StorageBreakdown;
use App\Storage\Application\Measurement\StorageTotal;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageBreakdownTest extends TestCase
{
    public function testTotalsByCategoryInDisplayOrder(): void
    {
        $totals = new StorageBreakdown($this->items())->byCategory();

        self::assertSame(['database', 'imports', 'media', 'application', 'temporary', 'other'], array_map(static fn (StorageTotal $total): string => $total->key, $totals));
        self::assertEquals(new StorageTotal('database', null, 0, false, []), $totals[0]);
        self::assertEquals(new StorageTotal('imports', null, 500, true, []), $totals[1]);
        self::assertEquals(new StorageTotal('media', null, 300, true, [MeasurementItemFlag::LeftoverInRelease]), $totals[2]);
        self::assertEquals(new StorageTotal('application', null, 1100, false, [MeasurementItemFlag::Incomplete]), $totals[3]);
        self::assertEquals(new StorageTotal('other', null, 0, true, []), $totals[5]);
    }

    public function testTotalsByLocationAndDetail(): void
    {
        $totals = new StorageBreakdown($this->items())->byLocation();

        self::assertEquals([
            new StorageTotal('shared', null, 500, true, []),
            new StorageTotal('current_release', '11', 1000, true, []),
            new StorageTotal('previous_release', '10', 400, false, [MeasurementItemFlag::LeftoverInRelease, MeasurementItemFlag::Incomplete]),
            new StorageTotal('database', null, 0, false, []),
        ], $totals);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sizes(): iterable
    {
        yield 'bytes' => [512, '512 B'];
        yield 'kilobytes' => [1536, '1.5 KB'];
        yield 'megabytes' => [5 * 1024 ** 2, '5.0 MB'];
        yield 'gigabytes' => [(int) (7.25 * 1024 ** 3), '7.25 GB'];
        yield 'terabytes' => [3 * 1024 ** 4, '3.00 TB'];
        yield 'beyond terabytes stays in TB' => [2048 * 1024 ** 4, '2048.00 TB'];
    }

    #[DataProvider('sizes')]
    public function testFormatsSizes(int $bytes, string $expected): void
    {
        self::assertSame($expected, ByteFormatter::format($bytes));
    }

    /**
     * @return list<StorageMeasurementItem>
     */
    private function items(): array
    {
        return [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 500),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 1000, locationDetail: '11'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Media, 'media_uploads', 300, locationDetail: '10', flags: [MeasurementItemFlag::LeftoverInRelease]),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 100, locationDetail: '10', flags: [MeasurementItemFlag::Incomplete]),
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed),
        ];
    }
}
