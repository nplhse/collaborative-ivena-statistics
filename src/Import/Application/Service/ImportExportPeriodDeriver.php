<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportSourceRangeReaderInterface;
use App\Import\Application\ExportPeriod\ExportPeriod;
use App\Import\Application\ExportPeriod\ExportPeriodDerivation;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps `import.export_starts_at` / `export_ends_at` in line with the imported rows.
 * Closure Analytics reads these periods as data coverage.
 */
final readonly class ImportExportPeriodDeriver
{
    public function __construct(
        private ImportSourceRangeReaderInterface $ranges,
        private EntityManagerInterface $entityManager,
        private ClosureRebuildRequestInterface $closureRebuildScheduler,
    ) {
    }

    /**
     * @param list<ExportPeriod>|null $allocationPeriods overrides the stored allocation periods of the hospital (dry runs)
     */
    public function derive(Import $import, ?array $allocationPeriods = null): ?ExportPeriod
    {
        $importId = $import->getId();
        if (null === $importId) {
            return null;
        }
        if (ImportType::CLOSURE === $import->getType()) {
            $range = $this->ranges->closureRange($importId);
            $hospitalId = $import->getHospital()?->getId();
            $periods = $allocationPeriods ?? (null === $hospitalId ? [] : $this->ranges->allocationExportPeriods($hospitalId));

            return ExportPeriodDerivation::forClosures($range[0] ?? null, $range[1] ?? null, $periods);
        }
        $range = $this->ranges->allocationRange($importId);

        return ExportPeriodDerivation::forAllocations($range[0] ?? null, $range[1] ?? null);
    }

    /**
     * Derives and stores the period on the entity. The caller flushes.
     *
     * @param list<ExportPeriod>|null $allocationPeriods
     *
     * @return bool true when the stored period changed
     */
    public function apply(Import $import, ?array $allocationPeriods = null): bool
    {
        $period = $this->derive($import, $allocationPeriods);

        return $import->applyDerivedExportPeriod($period?->startsAt, $period?->endsAt);
    }

    /**
     * Closure periods depend on allocation coverage. Re-derives them after an allocation
     * import and requests a closure analysis rebuild when one of them changed.
     */
    public function refreshClosureImports(int $hospitalId): void
    {
        $imports = $this->entityManager->getRepository(Import::class)->findBy([
            'hospital' => $hospitalId,
            'type' => ImportType::CLOSURE,
            'status' => [ImportStatus::COMPLETED, ImportStatus::PARTIAL],
        ]);
        if ([] === $imports) {
            return;
        }
        $periods = $this->ranges->allocationExportPeriods($hospitalId);
        $changed = 0;
        foreach ($imports as $import) {
            if ($this->apply($import, $periods)) {
                ++$changed;
            }
        }
        if ($changed > 0) {
            $this->entityManager->flush();
            $this->closureRebuildScheduler->requestAnalysis($hospitalId);
        }
    }

    /**
     * Coverage feeds Closure Analytics; a changed period invalidates its results.
     *
     * @return bool false when the hospital has no closure data or a rebuild is already queued
     */
    public function notifyCoverageChanged(int $hospitalId): bool
    {
        return $this->closureRebuildScheduler->requestAnalysis($hospitalId);
    }
}
