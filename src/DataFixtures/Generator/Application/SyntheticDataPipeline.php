<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Import\Application\Service\ImportDataCoverageCalculator;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Kpi\Application\Service\KpiAggregationService;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Runs synthetic imports through the same processing as uploaded ones (ADR 022).
 *
 * Per import: export period and data coverage. After generation: the allocation statistics
 * projection, the closure analysis with the closure volume projection, the materialized views and the
 * daily KPIs of the upload days. There is no separate projection code for synthetic data.
 */
final readonly class SyntheticDataPipeline
{
    public function __construct(
        private ImportExportPeriodDeriver $exportPeriods,
        private ImportDataCoverageCalculator $coverage,
        private AllocationStatsProjectionRebuildInterface $allocationProjection,
        private ClosureVolumeProjectionRebuildInterface $closureVolume,
        private MaterializedViewRefresherInterface $materializedViews,
        private KpiAggregationService $kpis,
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
        $this->aggregateKpis($this->uploadDays($allocationImportIds));
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

    /**
     * The daily KPIs of every day with an upload, after a full fixture load.
     *
     * `kpi_daily` counts imports by their upload day (`created_at`), and the generator sets it after the
     * data it covers (`UploadTime`). Unlike the closure rebuild this runs inside the fixture transaction.
     */
    public function aggregateAllKpis(): void
    {
        $this->aggregateKpis($this->uploadDays(null));
    }

    /**
     * @param list<\DateTimeImmutable> $days
     */
    private function aggregateKpis(array $days): void
    {
        foreach ($days as $day) {
            $this->kpis->aggregateForDate($day);
        }
    }

    /**
     * Upload days of the given allocation imports, or of all imports; a closure import shares the day of
     * its allocation import.
     *
     * @param list<int>|null $importIds
     *
     * @return list<\DateTimeImmutable>
     */
    private function uploadDays(?array $importIds): array
    {
        if ([] === $importIds) {
            return [];
        }

        $sql = 'SELECT DISTINCT CAST(created_at AS date) AS day FROM import';
        $params = [];
        $types = [];
        if (null !== $importIds) {
            $sql .= ' WHERE id IN (:ids)';
            $params['ids'] = $importIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }

        /** @var list<string> $days */
        $days = $this->connection->fetchFirstColumn($sql.' ORDER BY day', $params, $types);

        return array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day), $days);
    }
}
