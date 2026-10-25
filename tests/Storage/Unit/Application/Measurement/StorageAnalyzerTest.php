<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Measurement;

use App\Storage\Application\Capacity\BudgetCapacityProvider;
use App\Storage\Application\Capacity\CapacityResolver;
use App\Storage\Application\Capacity\FilesystemCapacityProvider;
use App\Storage\Application\Capacity\FilesystemSpace;
use App\Storage\Application\Contract\DatabaseSizeReaderInterface;
use App\Storage\Application\Contract\FileStorageScannerInterface;
use App\Storage\Application\Contract\FilesystemSpaceReaderInterface;
use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Measurement\StorageAnalyzer;
use App\Storage\Application\Scan\FileScan;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class StorageAnalyzerTest extends TestCase
{
    public function testCombinesFilesDatabaseAndBudget(): void
    {
        $analysis = $this->analyzer(budget: 5000)->analyze();
        $measurement = $analysis->measurement;

        self::assertTrue($analysis->isComplete());
        self::assertSame(4000, $measurement->getUsedBytes());
        self::assertSame(1000, $measurement->getDatabaseBytes());
        self::assertSame(CapacitySource::Budget, $measurement->getCapacitySource());
        self::assertSame(5000, $measurement->getCapacityBytes());
        self::assertSame(5000, $measurement->getBudgetBytes());
        self::assertSame(CapacityLevel::Notice, $measurement->getLevel());
        self::assertSame(100_000, $measurement->getFilesystemTotalBytes());
        self::assertSame(30_000, $measurement->getFilesystemFreeBytes());
        self::assertSame(MeasurementMode::Standalone, $measurement->getMode());
        self::assertSame(12, $analysis->scannedEntries);
        self::assertSame([], $analysis->failures);
        self::assertSame('2026-10-26', $measurement->getMeasuredOn()->format('Y-m-d'), 'the day is the day in Berlin');
        self::assertCount(3, $measurement->getItems());
        self::assertEquals(
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 1000),
            $measurement->getItems()[2],
        );
    }

    public function testComparesTheFilesystemUsageWhenConfigured(): void
    {
        $measurement = $this->analyzer(budget: 5000, source: 'filesystem')->analyze()->measurement;

        self::assertSame(CapacitySource::Filesystem, $measurement->getCapacitySource());
        self::assertSame(100_000, $measurement->getCapacityBytes());
        self::assertSame(CapacityLevel::Normal, $measurement->getLevel(), '70 % of the filesystem is in use');
        self::assertSame(4000, $measurement->getUsedBytes());
    }

    public function testRecordsAFailedDatabaseWithoutTotal(): void
    {
        $logger = new BufferingLogger();
        $analysis = $this->analyzer(budget: 5000, source: 'filesystem', databaseFails: true, logger: $logger)->analyze();
        $measurement = $analysis->measurement;

        self::assertFalse($analysis->isComplete());
        self::assertNull($measurement->getUsedBytes());
        self::assertNull($measurement->getDatabaseBytes());
        self::assertSame(CapacityLevel::Unknown, $measurement->getLevel(), 'no level without a total, even for the filesystem');
        self::assertSame(MeasurementItemStatus::Failed, $measurement->getItems()[2]->status);
        self::assertSame(['database: connection refused'], $analysis->failures);
        self::assertSame('warning', $logger->cleanLogs()[0][0]);
    }

    public function testAnIncompleteScanHasNoTotal(): void
    {
        $logger = new BufferingLogger();
        $analysis = $this->analyzer(budget: 5000, complete: false, logger: $logger)->analyze();

        self::assertNull($analysis->measurement->getUsedBytes());
        self::assertSame(CapacityLevel::Unknown, $analysis->measurement->getLevel());
        self::assertSame(CapacitySource::Budget, $analysis->measurement->getCapacitySource());
        self::assertSame(['/app/releases/10: not a readable directory'], $analysis->failures);
        self::assertStringContainsString('could not read {count} path(s)', $logger->cleanLogs()[0][1]);
    }

    public function testWithoutReferenceTheLevelIsUnknown(): void
    {
        $measurement = $this->analyzer(budget: 0, filesystem: false)->analyze()->measurement;

        self::assertSame(CapacitySource::None, $measurement->getCapacitySource());
        self::assertNull($measurement->getCapacityBytes());
        self::assertNull($measurement->getBudgetBytes());
        self::assertNull($measurement->getFilesystemTotalBytes());
        self::assertSame(CapacityLevel::Unknown, $measurement->getLevel());
        self::assertSame(4000, $measurement->getUsedBytes());
    }

    private function analyzer(
        int $budget,
        string $source = 'budget',
        bool $databaseFails = false,
        bool $complete = true,
        bool $filesystem = true,
        ?BufferingLogger $logger = null,
    ): StorageAnalyzer {
        $settings = new StorageSettings(80, 90, 99, $budget, $source, 36);
        $scan = new FileScan(
            [
                new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'vendor', 2500),
                $complete
                    ? new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Imports, 'var_imports', 500)
                    : new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Other, 'release', null, MeasurementItemStatus::Failed, '10'),
            ],
            12,
            $complete ? [] : ['/app/releases/10: not a readable directory'],
            $complete,
        );

        return new StorageAnalyzer(
            new class implements StorageLayoutDetectorInterface {
                public function detect(): StorageLayout
                {
                    return StorageLayout::standalone('/app');
                }
            },
            new readonly class($scan) implements FileStorageScannerInterface {
                public function __construct(private FileScan $scan)
                {
                }

                public function scan(StorageLayout $layout): FileScan
                {
                    return $this->scan;
                }
            },
            new readonly class($databaseFails) implements DatabaseSizeReaderInterface {
                public function __construct(private bool $fails)
                {
                }

                public function bytes(): int
                {
                    if ($this->fails) {
                        throw new \RuntimeException('connection refused');
                    }

                    return 1000;
                }
            },
            new readonly class($filesystem) implements FilesystemSpaceReaderInterface {
                public function __construct(private bool $available)
                {
                }

                public function read(string $path): ?FilesystemSpace
                {
                    return $this->available ? new FilesystemSpace(100_000, 30_000) : null;
                }
            },
            new CapacityResolver($settings, [new BudgetCapacityProvider($settings), new FilesystemCapacityProvider()]),
            $settings,
            new MockClock(new \DateTimeImmutable('2026-10-25 23:30:00', new \DateTimeZone('UTC'))),
            $logger ?? new NullLogger(),
        );
    }
}
