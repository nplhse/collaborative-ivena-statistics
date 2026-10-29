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
    /** Steps of the asynchronous stage of a generation run, as stored for the wizard's summary. */
    public const string STEP_ALLOCATIONS = 'allocation projection';

    public const string STEP_CLOSURES = 'closure analysis and volume';

    public const string STEP_VIEWS = 'materialized views';

    public const string STEP_KPIS = 'daily KPIs';

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
     * The per-hospital part of afterImports() for the asynchronous stage of a generation run: allocation
     * projections of the hospital's imports first, then its closure analysis and closure volume, which read them.
     * Both steps replace their rows, so running them again after a failed attempt gives the same result.
     *
     * $onStep hears each step before it runs: one per allocation import, then the closures (total: imports + 1).
     *
     * @param list<int>                                                  $allocationImportIds
     * @param (callable(string $step, int $done, int $total): void)|null $onStep
     */
    public function projectHospital(int $hospitalId, array $allocationImportIds, ?callable $onStep = null): void
    {
        $total = \count($allocationImportIds) + 1;
        foreach ($allocationImportIds as $index => $importId) {
            if (null !== $onStep) {
                $onStep(self::STEP_ALLOCATIONS, $index, $total);
            }
            $this->allocationProjection->rebuildForImport($importId);
        }
        if (null !== $onStep) {
            $onStep(self::STEP_CLOSURES, $total - 1, $total);
        }
        $this->closureVolume->rebuild(hospitalIds: [$hospitalId]);
    }

    /**
     * The shared part of afterImports() once every hospital of a generation run is projected: materialized
     * views and the daily KPIs of the upload days, plus the upload days of imports the run deleted before.
     *
     * @param list<int>                           $allocationImportIds
     * @param list<\DateTimeImmutable>            $removedUploadDays
     * @param (callable(string $step): void)|null $onStep              hears each step before it runs
     */
    public function finishImports(array $allocationImportIds, array $removedUploadDays = [], ?callable $onStep = null): void
    {
        if (null !== $onStep) {
            $onStep(self::STEP_VIEWS);
        }
        $this->materializedViews->refresh();
        if (null !== $onStep) {
            $onStep(self::STEP_KPIS);
        }
        $days = [];
        foreach ([...$this->uploadDays($allocationImportIds), ...$removedUploadDays] as $day) {
            $days[$day->format('Y-m-d')] = $day;
        }
        ksort($days);
        $this->aggregateKpis(array_values($days));
    }

    /**
     * After imports were deleted (the import deletion removed their projection rows already): closure
     * volume of the hospitals, views and the KPIs of the upload days of the deleted imports.
     *
     * @param list<int>                $hospitalIds
     * @param list<\DateTimeImmutable> $uploadDays
     */
    public function afterRemoval(array $hospitalIds, array $uploadDays): void
    {
        if ([] !== $hospitalIds) {
            $this->closureVolume->rebuild(hospitalIds: array_values(array_unique($hospitalIds)));
        }
        $this->materializedViews->refresh();
        $this->aggregateKpis($uploadDays);
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
