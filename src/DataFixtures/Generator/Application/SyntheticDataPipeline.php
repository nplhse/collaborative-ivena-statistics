<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Import\Application\Service\ImportDataCoverageCalculator;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use Doctrine\DBAL\Connection;

/**
 * Runs synthetic imports through the same processing as uploaded ones (ADR 022).
 *
 * Per import: export period and data coverage. After generation: the allocation statistics
 * projection, the closure analysis with the closure volume projection, and the materialized views.
 * There is no separate projection code for synthetic data.
 */
final readonly class SyntheticDataPipeline
{
    public function __construct(
        private ImportExportPeriodDeriver $exportPeriods,
        private ImportDataCoverageCalculator $coverage,
        private AllocationStatsProjectionRebuildInterface $allocationProjection,
        private ClosureVolumeProjectionRebuildInterface $closureVolume,
        private MaterializedViewRefresherInterface $materializedViews,
        private Connection $connection,
    ) {
    }

    /**
     * Export period and data coverage; the caller flushes. Closure periods depend on the allocations
     * of the same hospital, so closure imports are processed after the allocations are written.
     *
     * A month without closures has no rows to derive a period from. Like an empty IVENA export it
     * still covers its window, so the known window is stored as a manual period; the closure analysis
     * then counts the month as covered without closures.
     */
    public function processImport(Import $import, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): void
    {
        $this->exportPeriods->apply($import);
        if (!$import->getExportStartsAt() instanceof \DateTimeImmutable) {
            $import->setExportStartsAt($windowStart)
                ->setExportEndsAt($windowEnd)
                ->setExportPeriodSource(ImportExportPeriodSource::MANUAL);
        }
        $this->coverage->apply($import);
    }

    /**
     * Projections for a few new imports, for example a month added later.
     *
     * @param list<int> $allocationImportIds
     * @param list<int> $hospitalIds
     */
    public function afterImports(array $allocationImportIds, array $hospitalIds): void
    {
        foreach ($allocationImportIds as $importId) {
            $this->allocationProjection->rebuildForImport($importId);
        }
        if ([] !== $hospitalIds) {
            $this->closureVolume->rebuild(hospitalIds: array_values(array_unique($hospitalIds)));
        }
        $this->materializedViews->refresh();
    }

    /**
     * The allocation statistics projection from scratch, after a full fixture load.
     *
     * Fixtures run inside one database transaction, and the closure volume projection takes a lock that
     * cannot be acquired there; `composer load-fixtures` therefore runs the closure rebuild and the
     * materialized view refresh as separate commands after the load.
     */
    public function rebuildAllocationProjection(): void
    {
        $this->connection->executeStatement('TRUNCATE allocation_stats_projection RESTART IDENTITY');

        /** @var list<int|string> $importIds */
        $importIds = $this->connection->fetchFirstColumn('SELECT DISTINCT import_id FROM allocation ORDER BY import_id ASC');
        foreach ($importIds as $importId) {
            $this->allocationProjection->rebuildForImport((int) $importId);
        }
    }
}
