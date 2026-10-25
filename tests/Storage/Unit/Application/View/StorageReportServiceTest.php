<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\View;

use App\Storage\Application\Contract\DatabaseTableSizeReaderInterface;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\DTO\DatabaseTableSize;
use App\Storage\Application\View\StorageDiskUsageBuilder;
use App\Storage\Application\View\StorageReportService;
use App\Storage\Application\View\StorageSummaryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class StorageReportServiceTest extends TestCase
{
    public function testHasNothingToShowBeforeTheFirstMeasurement(): void
    {
        $repository = $this->createMock(StorageMeasurementRepositoryInterface::class);
        $repository->method('latest')->willReturn(null);
        $repository->expects(self::never())->method('findSince');

        $tables = $this->createMock(DatabaseTableSizeReaderInterface::class);
        $tables->expects(self::never())->method('largestTables');

        $service = $this->service($repository, $tables);

        self::assertNull($service->summary());
        self::assertNull($service->diskUsage());
    }

    public function testReadsTheLatestMeasurementAndOneYearOfHistory(): void
    {
        $latest = StorageViewFixtures::measurement('2026-10-25');
        $older = StorageViewFixtures::measurement('2026-10-24');

        $repository = $this->createMock(StorageMeasurementRepositoryInterface::class);
        $repository->method('latest')->willReturn($latest);
        $repository->expects(self::once())
            ->method('findSince')
            ->with(self::callback(static fn (\DateTimeImmutable $day): bool => '2025-10-26' === $day->format('Y-m-d')))
            ->willReturn([$older, $latest]);

        $tables = $this->createMock(DatabaseTableSizeReaderInterface::class);
        $tables->expects(self::once())->method('largestTables')->with(StorageReportService::LARGEST_TABLES_LIMIT)
            ->willReturn([new DatabaseTableSize('public', 'import', 10)]);

        $service = $this->service($repository, $tables);

        self::assertSame(1000, $service->summary()?->capacityBytes);
        $usage = $service->diskUsage();
        self::assertNotNull($usage);
        self::assertSame(['2026-10-24', '2026-10-25'], $usage->history->days);
        self::assertCount(1, $usage->largestTables ?? []);
    }

    public function testShowsThePageWhenTheTableSizesCannotBeRead(): void
    {
        $repository = $this->createStub(StorageMeasurementRepositoryInterface::class);
        $repository->method('latest')->willReturn(StorageViewFixtures::measurement());
        $repository->method('findSince')->willReturn([]);

        $tables = $this->createStub(DatabaseTableSizeReaderInterface::class);
        $tables->method('largestTables')->willThrowException(new \RuntimeException('Table sizes are only supported on PostgreSQL.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $usage = $this->service($repository, $tables, $logger)->diskUsage();

        self::assertNotNull($usage);
        self::assertNull($usage->largestTables);
    }

    private function service(
        StorageMeasurementRepositoryInterface $repository,
        DatabaseTableSizeReaderInterface $tables,
        ?LoggerInterface $logger = null,
    ): StorageReportService {
        $settings = StorageViewFixtures::settings();
        $summaryBuilder = new StorageSummaryBuilder($settings, new MockClock(new \DateTimeImmutable('2026-10-25 12:00')));

        return new StorageReportService(
            $repository,
            $tables,
            $summaryBuilder,
            new StorageDiskUsageBuilder($summaryBuilder, $settings),
            $logger ?? new NullLogger(),
        );
    }
}
