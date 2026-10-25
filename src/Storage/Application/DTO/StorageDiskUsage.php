<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\MeasurementMode;

/**
 * Everything the Disk Usage page shows, built from the latest measurement, its history and the live table sizes.
 */
final readonly class StorageDiskUsage
{
    /**
     * @param list<StorageCategoryRow>     $categories    display order
     * @param list<StorageLocationRow>     $locations     current release, previous releases, shared, rest
     * @param list<DatabaseTableSize>|null $largestTables null when the database could not report them
     */
    public function __construct(
        public StorageSummary $summary,
        public array $categories,
        public array $locations,
        public StorageCapacityOverview $capacity,
        public StorageHistory $history,
        public ?int $databaseBytes,
        public ?array $largestTables,
        public ?\DateTimeImmutable $compared7DaysOn,
        public ?\DateTimeImmutable $compared30DaysOn,
    ) {
    }

    public function isDeployer(): bool
    {
        return MeasurementMode::Deployer === $this->summary->mode;
    }

    public function tableSharePercent(DatabaseTableSize $table): ?float
    {
        return null !== $this->databaseBytes && $this->databaseBytes > 0 ? round((float) $table->bytes / (float) $this->databaseBytes * 100.0, 1) : null;
    }
}
