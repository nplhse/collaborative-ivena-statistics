<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Domain\ValueObject;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageMeasurementItemTest extends TestCase
{
    public function testRoundTripsThroughArray(): void
    {
        $item = new StorageMeasurementItem(
            location: StorageLocation::PreviousRelease,
            category: StorageCategory::Media,
            contributor: 'public_uploads_media',
            bytes: 5_368_709_120,
            locationDetail: '41',
            flags: [MeasurementItemFlag::LeftoverInRelease],
        );

        $data = $item->toArray();

        self::assertSame([
            'location' => 'previous_release',
            'location_detail' => '41',
            'category' => 'media',
            'contributor' => 'public_uploads_media',
            'bytes' => 5_368_709_120,
            'status' => 'measured',
            'flags' => ['leftover_in_release'],
        ], $data);
        self::assertEquals($item, StorageMeasurementItem::fromArray($data));
    }

    public function testFailedItemHasNoSize(): void
    {
        $item = new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed);

        self::assertNull($item->bytes);
        self::assertSame('failed', $item->toArray()['status']);
    }

    /**
     * @return iterable<string, array{string, int|null, MeasurementItemStatus, string|null}>
     */
    public static function invalidItems(): iterable
    {
        yield 'empty contributor' => ['', 1, MeasurementItemStatus::Measured, null];
        yield 'contributor with path separator' => ['var/cache', 1, MeasurementItemStatus::Measured, null];
        yield 'measured without size' => ['vendor', null, MeasurementItemStatus::Measured, null];
        yield 'negative size' => ['vendor', -1, MeasurementItemStatus::Measured, null];
        yield 'failed with size' => ['vendor', 0, MeasurementItemStatus::Failed, null];
        yield 'unavailable with size' => ['vendor', 10, MeasurementItemStatus::Unavailable, null];
        yield 'empty location detail' => ['vendor', 10, MeasurementItemStatus::Measured, ''];
    }

    #[DataProvider('invalidItems')]
    public function testRejectsInvalidItems(string $contributor, ?int $bytes, MeasurementItemStatus $status, ?string $detail): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, $contributor, $bytes, $status, $detail);
    }

    public function testRejectsMalformedArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StorageMeasurementItem::fromArray(['location' => 'project', 'category' => 'application', 'contributor' => 'vendor', 'bytes' => '12', 'status' => 'measured']);
    }

    public function testRejectsUnknownEnumValues(): void
    {
        $this->expectException(\ValueError::class);

        StorageMeasurementItem::fromArray(['location' => 'nowhere', 'category' => 'application', 'contributor' => 'vendor', 'bytes' => 12, 'status' => 'measured']);
    }
}
