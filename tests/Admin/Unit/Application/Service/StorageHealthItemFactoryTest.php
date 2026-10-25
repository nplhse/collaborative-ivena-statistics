<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\StorageHealthItemFactory;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StorageHealthItemFactoryTest extends TestCase
{
    public function testShowsUsageCapacityRemainingLevelAndTimeAndLinksToDiskUsage(): void
    {
        $measuredAt = new \DateTimeImmutable('2026-10-25 03:30');
        $measurement = StorageMeasurementBuilder::create($measuredAt, CapacityLevel::Warning, usedBytes: 9 * 1024 ** 3);

        $item = $this->factory($measurement)->create('warning');

        self::assertSame('storage', $item->key);
        self::assertSame('ops.health.check.storage', $item->labelKey);
        self::assertSame('warning', $item->value);
        self::assertSame('warning', $item->severity);
        self::assertSame('fas fa-triangle-exclamation', $item->icon);
        self::assertSame('/admin', $item->detailUrl);
        self::assertNotNull($item->storage);
        self::assertSame('9.00 GB', $item->storage->used);
        self::assertSame('10.00 GB', $item->storage->capacity);
        self::assertSame('ops.health.storage.source.budget', $item->storage->capacitySourceLabelKey);
        self::assertSame(10.0, $item->storage->remainingPercent);
        self::assertSame('ops.health.storage.level.warning', $item->storage->levelLabelKey);
        self::assertSame($measuredAt, $item->storage->measuredAt);
    }

    public function testRemainingPercentNeverGoesBelowZero(): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable(), CapacityLevel::Exceeded, usedBytes: 12 * 1024 ** 3);

        $item = $this->factory($measurement)->create('exceeded');

        self::assertSame('danger', $item->severity);
        self::assertSame(0.0, $item->storage?->remainingPercent);
    }

    public function testWithoutReferenceOrTotalThereIsNoRemainingPercent(): void
    {
        $withoutReference = StorageMeasurementBuilder::create(new \DateTimeImmutable(), CapacityLevel::Unknown, source: CapacitySource::None);
        $withoutTotal = StorageMeasurementBuilder::create(new \DateTimeImmutable(), CapacityLevel::Unknown, usedBytes: null);

        $notConfigured = $this->factory($withoutReference)->create('not_configured');
        $incomplete = $this->factory($withoutTotal)->create('incomplete');

        self::assertSame('success', $notConfigured->severity);
        self::assertSame('fas fa-hard-drive', $notConfigured->icon);
        self::assertNull($notConfigured->storage?->capacity);
        self::assertNull($notConfigured->storage?->remainingPercent);
        self::assertSame('ops.health.storage.source.none', $notConfigured->storage?->capacitySourceLabelKey);
        self::assertSame('warning', $incomplete->severity);
        self::assertNull($incomplete->storage?->used);
        self::assertNull($incomplete->storage?->remainingPercent);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function severities(): iterable
    {
        yield 'ok' => ['ok', 'success'];
        yield 'notice' => ['notice', 'success'];
        yield 'stale' => ['stale', 'warning'];
        yield 'critical' => ['critical', 'danger'];
        yield 'unexpected value' => ['something else', 'warning'];
    }

    #[DataProvider('severities')]
    public function testSeverityFollowsTheHealthStatusOfTheValue(string $value, string $severity): void
    {
        self::assertSame($severity, $this->factory(null)->create($value)->severity);
    }

    public function testWithoutMeasurementThereAreNoDetails(): void
    {
        $item = $this->factory(null)->create('unavailable');

        self::assertSame('warning', $item->severity);
        self::assertNull($item->storage);
        self::assertSame('/admin', $item->detailUrl);
    }

    private function factory(?StorageMeasurement $latest): StorageHealthItemFactory
    {
        $repository = $this->createStub(StorageMeasurementRepositoryInterface::class);
        $repository->method('latest')->willReturn($latest);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with(StorageHealthItemFactory::DISK_USAGE_ROUTE)->willReturn('/admin');

        return new StorageHealthItemFactory($repository, $urlGenerator);
    }
}
