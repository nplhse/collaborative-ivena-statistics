<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Application\Capacity\CapacityResolver;
use App\Storage\Application\Contract\DatabaseSizeReaderInterface;
use App\Storage\Application\Contract\FileStorageScannerInterface;
use App\Storage\Application\Contract\FilesystemSpaceReaderInterface;
use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Measures files, database and capacity once (ADR 017). Parts fail independently: a failed part is recorded
 * without size, and without files or database there is no total and the level is unknown.
 */
final readonly class StorageAnalyzer
{
    private const string TIMEZONE = 'Europe/Berlin';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private StorageLayoutDetectorInterface $layoutDetector,
        private FileStorageScannerInterface $scanner,
        private DatabaseSizeReaderInterface $databaseSizeReader,
        private FilesystemSpaceReaderInterface $filesystemSpaceReader,
        private CapacityResolver $capacityResolver,
        private StorageSettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function analyze(): StorageAnalysis
    {
        $startedAt = $this->clock->now();
        $start = hrtime(true);

        $layout = $this->layoutDetector->detect();
        $scan = $this->scanner->scan($layout);
        $items = $scan->items;
        $failures = $scan->failures;

        try {
            $databaseBytes = $this->databaseSizeReader->bytes();
            $items[] = new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', $databaseBytes);
        } catch (\Throwable $exception) {
            $databaseBytes = null;
            $items[] = new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed);
            $failures[] = 'database: '.$exception->getMessage();
            $this->logger->warning('Storage measurement could not read the database size.', ['exception' => $exception]);
        }

        $filesBytes = $scan->totalBytes();
        $usedBytes = null !== $filesBytes && null !== $databaseBytes ? $filesBytes + $databaseBytes : null;
        $filesystem = $this->filesystemSpaceReader->read($layout->root);
        $reference = $this->capacityResolver->resolve($usedBytes, $filesystem);
        $level = null === $usedBytes
            ? CapacityLevel::Unknown
            : $this->settings->thresholds->levelFor($reference?->comparedUsedBytes, $reference?->capacityBytes);

        if ([] !== $scan->failures) {
            $this->logger->warning('Storage measurement could not read {count} path(s).', ['count' => \count($scan->failures)]);
        }

        $measurement = new StorageMeasurement(
            measuredOn: $startedAt->setTimezone(new \DateTimeZone(self::TIMEZONE)),
            measuredAt: $startedAt,
            durationMs: intdiv(hrtime(true) - $start, 1_000_000),
            mode: $layout->mode,
            usedBytes: $usedBytes,
            databaseBytes: $databaseBytes,
            capacitySource: $reference->source ?? CapacitySource::None,
            capacityBytes: $reference?->capacityBytes,
            budgetBytes: $this->settings->budgetBytes,
            filesystemTotalBytes: $filesystem?->totalBytes,
            filesystemFreeBytes: $filesystem?->freeBytes,
            level: $level,
            items: $items,
        );

        return new StorageAnalysis($measurement, $layout, $scan->entries, $failures);
    }
}
