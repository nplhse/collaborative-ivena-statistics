<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\DataStatusFinding;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataStatus;
use App\Allocation\Application\DTO\MyHospitals\HospitalImportSummary;
use App\Allocation\Application\DTO\MyHospitals\MonthCoverageState;
use App\Allocation\Application\DTO\MyHospitals\MonthStatus;
use App\Allocation\Application\DTO\MyHospitals\MyHospitalsOverview;
use App\Allocation\Application\DTO\MyHospitals\OverviewImports;
use App\Allocation\Application\DTO\MyHospitals\OverviewMonth;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Application\StatisticsPeriod;
use Psr\Clock\ClockInterface;

/**
 * Turns cached snapshots into what the viewer may see. Everything that depends on the viewer (permissions, own
 * uploads) or on the date (current month, twelve-month window, findings) is derived here, per request.
 */
final readonly class MyHospitalsOverviewBuilder
{
    public function __construct(
        private CachedHospitalDataSnapshotReader $reader,
        private MonthlyCoverageClassifier $classifier,
        private DataStatusFindingRules $findingRules,
        private ClockInterface $clock,
    ) {
    }

    public function build(MyHospitalsScope $scope, int $userId): MyHospitalsOverview
    {
        $now = $this->clock->now();
        $windowStart = StatisticsPeriod::overviewPeriodStart();

        $hospitalIds = [];
        foreach ($scope->entries as $entry) {
            $id = $entry->hospital->getId();
            if (null !== $id) {
                $hospitalIds[] = $id;
            }
        }
        $snapshots = $this->reader->read($hospitalIds);

        $statuses = [];
        foreach ($scope->entries as $entry) {
            $snapshot = $snapshots[(int) $entry->hospital->getId()] ?? null;
            if ($snapshot instanceof HospitalDataSnapshot) {
                $statuses[] = $this->hospitalStatus($entry, $snapshot, $userId, $windowStart, $now);
            }
        }

        return $this->overview($statuses);
    }

    private function hospitalStatus(
        MyHospitalsEntry $entry,
        HospitalDataSnapshot $snapshot,
        int $userId,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $now,
    ): HospitalDataStatus {
        $history = $this->classifier->classify($snapshot, $now);
        $gaps = $this->classifier->gaps($history);
        $findings = array_values(array_filter(
            $this->findingRules->findings($snapshot, $history, $gaps, $windowStart, $now),
            static fn (DataStatusFinding $finding): bool => $entry->has($finding->type->requiredPermission()),
        ));

        $imports = null;
        if ($entry->has(HospitalPermission::Import)) {
            $own = $snapshot->uploaders[$userId] ?? null;
            $imports = new HospitalImportSummary(
                relevant: $snapshot->relevantImports,
                partial: $snapshot->partialImports,
                failed: $snapshot->failedImports,
                cancelled: $snapshot->cancelledImports,
                pending: $snapshot->pendingImports,
                lastUploadAt: $snapshot->lastUploadAt,
                ownImports: $own->importCount ?? 0,
                ownLastUploadAt: $own?->lastUploadAt,
            );
        }

        $months = null;
        $fullyCovered = null;
        if ($entry->has(HospitalPermission::Statistics)) {
            $months = $this->classifier->window($history, $windowStart, $now);
            $fullyCovered = \count(array_filter($months, static fn (MonthStatus $month): bool => $month->state->isFullyCovered()));
        }

        return new HospitalDataStatus(
            entry: $entry,
            allocationCount: $snapshot->allocationCount,
            firstAllocationAt: $snapshot->firstAllocationAt,
            lastAllocationAt: $snapshot->lastAllocationAt,
            imports: $imports,
            months: $months,
            fullyCoveredMonths: $fullyCovered,
            gaps: null === $months ? null : $gaps,
            findings: $findings,
        );
    }

    /**
     * @param list<HospitalDataStatus> $statuses
     */
    private function overview(array $statuses): MyHospitalsOverview
    {
        $allocations = 0;
        $first = null;
        $last = null;
        $importStatuses = [];
        $statisticsStatuses = [];
        foreach ($statuses as $status) {
            $allocations += $status->allocationCount;
            $first = $this->earlier($first, $status->firstAllocationAt);
            $last = $this->later($last, $status->lastAllocationAt);
            if ($status->imports instanceof HospitalImportSummary) {
                $importStatuses[] = $status->imports;
            }
            if (null !== $status->months) {
                $statisticsStatuses[] = $status;
            }
        }

        return new MyHospitalsOverview(
            hospitals: $statuses,
            allocationCount: $allocations,
            firstAllocationAt: $first,
            lastAllocationAt: $last,
            imports: $this->overviewImports($importStatuses),
            months: $this->overviewMonths($statisticsStatuses),
            statisticsHospitals: \count($statisticsStatuses),
        );
    }

    /**
     * @param list<HospitalImportSummary> $summaries
     */
    private function overviewImports(array $summaries): ?OverviewImports
    {
        if ([] === $summaries) {
            return null;
        }

        $relevant = 0;
        $problems = 0;
        $own = 0;
        $lastUpload = null;
        $ownLastUpload = null;
        foreach ($summaries as $summary) {
            $relevant += $summary->relevant;
            $problems += $summary->failed + $summary->cancelled;
            $own += $summary->ownImports;
            $lastUpload = $this->later($lastUpload, $summary->lastUploadAt);
            $ownLastUpload = $this->later($ownLastUpload, $summary->ownLastUploadAt);
        }

        return new OverviewImports(\count($summaries), $relevant, $problems, $lastUpload, $own, $ownLastUpload);
    }

    /**
     * @param list<HospitalDataStatus> $statuses hospitals with STATISTICS, so `months` is set
     *
     * @return list<OverviewMonth>|null
     */
    private function overviewMonths(array $statuses): ?array
    {
        if ([] === $statuses) {
            return null;
        }

        /** @var array<string, array{month: \DateTimeImmutable, allocations: int, hospitals: int, covered: int, gaps: list<string>, inProgress: bool}> $months */
        $months = [];
        foreach ($statuses as $status) {
            foreach ($status->months ?? [] as $month) {
                $key = $month->month->format('Y-m');
                $months[$key] ??= ['month' => $month->month, 'allocations' => 0, 'hospitals' => 0, 'covered' => 0, 'gaps' => [], 'inProgress' => MonthCoverageState::InProgress === $month->state];
                $months[$key]['allocations'] += $month->allocations;
                if (MonthCoverageState::Outside !== $month->state) {
                    ++$months[$key]['hospitals'];
                }
                if ($month->state->isFullyCovered()) {
                    ++$months[$key]['covered'];
                }
                if (MonthCoverageState::Missing === $month->state || MonthCoverageState::Partial === $month->state) {
                    $months[$key]['gaps'][] = (string) $status->entry->hospital->getName();
                }
            }
        }

        return array_values(array_map(
            static fn (array $month): OverviewMonth => new OverviewMonth(
                $month['month'],
                $month['allocations'],
                $month['hospitals'],
                $month['covered'],
                $month['gaps'],
                $month['inProgress'],
            ),
            $months,
        ));
    }

    private function earlier(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if (!$a instanceof \DateTimeImmutable) {
            return $b;
        }

        return $b instanceof \DateTimeImmutable && $b < $a ? $b : $a;
    }

    private function later(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if (!$a instanceof \DateTimeImmutable) {
            return $b;
        }

        return $b instanceof \DateTimeImmutable && $b > $a ? $b : $a;
    }
}
