<?php

declare(strict_types=1);

namespace App\Storage\Application\View;

use App\Storage\Application\Contract\DatabaseTableSizeReaderInterface;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\DTO\DatabaseTableSize;
use App\Storage\Application\DTO\StorageDiskUsage;
use App\Storage\Application\DTO\StorageSummary;
use Psr\Log\LoggerInterface;

/**
 * Read side for the Admin dashboard card and the Disk Usage page. Reads persisted measurements only; the largest
 * tables are the one live query, and a failure there does not break the page.
 */
final readonly class StorageReportService
{
    public const int LARGEST_TABLES_LIMIT = 10;

    /** The history covers one year, the longest chart range. */
    public const int HISTORY_DAYS = 365;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private StorageMeasurementRepositoryInterface $measurements,
        private DatabaseTableSizeReaderInterface $tableSizeReader,
        private StorageSummaryBuilder $summaryBuilder,
        private StorageDiskUsageBuilder $diskUsageBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function summary(): ?StorageSummary
    {
        $latest = $this->measurements->latest();

        return $latest instanceof \App\Storage\Domain\Entity\StorageMeasurement ? $this->summaryBuilder->build($latest) : null;
    }

    public function diskUsage(): ?StorageDiskUsage
    {
        $latest = $this->measurements->latest();
        if (!$latest instanceof \App\Storage\Domain\Entity\StorageMeasurement) {
            return null;
        }

        $history = $this->measurements->findSince($latest->getMeasuredOn()->modify(sprintf('-%d days', self::HISTORY_DAYS - 1)));

        return $this->diskUsageBuilder->build($latest, $history, $this->largestTables());
    }

    /**
     * @return list<DatabaseTableSize>|null
     */
    private function largestTables(): ?array
    {
        try {
            return $this->tableSizeReader->largestTables(self::LARGEST_TABLES_LIMIT);
        } catch (\Throwable $exception) {
            $this->logger->warning('Disk Usage page could not read the largest database tables.', ['exception' => $exception]);

            return null;
        }
    }
}
