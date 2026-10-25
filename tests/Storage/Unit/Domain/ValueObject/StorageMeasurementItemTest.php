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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedArrays(): iterable
    {
        $valid = ['location' => 'project', 'category' => 'application', 'contributor' => 'vendor', 'bytes' => 12, 'status' => 'measured', 'location_detail' => null, 'flags' => []];

        yield 'size as string' => [['bytes' => '12'] + $valid];
        yield 'missing location' => [array_diff_key($valid, ['location' => true])];
        yield 'category not a string' => [['category' => 3] + $valid];
        yield 'missing contributor' => [array_diff_key($valid, ['contributor' => true])];
        yield 'status not a string' => [['status' => null] + $valid];
        yield 'location detail not a string' => [['location_detail' => 41] + $valid];
        yield 'flags not a list' => [['flags' => 'leftover_in_release'] + $valid];
        yield 'flag not a string' => [['flags' => [1]] + $valid];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('malformedArrays')]
    public function testRejectsMalformedArray(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StorageMeasurementItem::fromArray($data);
    }

    public function testReadsAnArrayWithoutOptionalKeys(): void
    {
        $item = StorageMeasurementItem::fromArray(['location' => 'project', 'category' => 'temporary', 'contributor' => 'var_cache', 'status' => 'unavailable']);

        self::assertNull($item->bytes);
        self::assertNull($item->locationDetail);
        self::assertSame([], $item->flags);
        self::assertSame(MeasurementItemStatus::Unavailable, $item->status);
    }

    public function testRejectsUnknownEnumValues(): void
    {
        $this->expectException(\ValueError::class);

        StorageMeasurementItem::fromArray(['location' => 'nowhere', 'category' => 'application', 'contributor' => 'vendor', 'bytes' => 12, 'status' => 'measured']);
    }
}
