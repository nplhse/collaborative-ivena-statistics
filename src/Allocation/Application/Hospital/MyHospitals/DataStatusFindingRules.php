<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\DataStatusFinding;
use App\Allocation\Application\DTO\MyHospitals\DataStatusFindingType;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Application\DTO\MyHospitals\ImportIssueKind;
use App\Allocation\Application\DTO\MyHospitals\MonthGap;
use App\Allocation\Application\DTO\MyHospitals\MonthStatus;

/**
 * Simple, explainable rules that point at data worth a look. They describe, they do not judge: a peak or a quiet
 * month is not an error, and a gap may be legitimate.
 */
final class DataStatusFindingRules
{
    /** Import problems older than this are history, not a current hint. */
    public const int RECENT_IMPORT_DAYS = 90;

    /**
     * A gap of days without records counts only when the hospital would normally have recorded at least this many
     * records in it: records per day of the import × gap length (#1092). A large hospital gets a hint for a single
     * empty day, a hospital with about one record a day only from five empty days in a row.
     */
    public const float DAY_GAP_MIN_EXPECTED_RECORDS = 5.0;

    /** The latest allocation is older than this many days. */
    public const int NOT_UPDATED_AFTER_DAYS = 90;

    /** Relative deviation from the baseline median that counts as a notable change. */
    public const float VOLUME_CHANGE_THRESHOLD = 0.5;

    /** At most this many earlier fully covered months form the baseline. */
    public const int VOLUME_BASELINE_MONTHS = 12;

    /** Fewer fully covered earlier months than this: no comparison. */
    public const int VOLUME_MIN_BASELINE_MONTHS = 6;

    /**
     * @param list<MonthStatus>  $months      whole history, see {@see MonthlyCoverageClassifier::classify()}
     * @param list<MonthGap>     $gaps
     * @param \DateTimeImmutable $windowStart volume changes are only reported for months from here on
     *
     * @return list<DataStatusFinding>
     */
    public function findings(
        HospitalDataSnapshot $snapshot,
        array $months,
        array $gaps,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $now,
    ): array {
        return [
            ...$this->importFindings($snapshot, $now),
            ...$this->notUpdatedFindings($snapshot, $now),
            ...array_map(
                static fn (MonthGap $gap): DataStatusFinding => new DataStatusFinding(
                    DataStatusFindingType::PossibleGap,
                    amount: $gap->months,
                    periodStart: $gap->firstMonth,
                    periodEnd: $gap->lastMonth,
                ),
                $gaps,
            ),
            ...$this->volumeChangeFindings($months, $windowStart),
        ];
    }

    /**
     * @return list<DataStatusFinding>
     */
    private function importFindings(HospitalDataSnapshot $snapshot, \DateTimeImmutable $now): array
    {
        $recentFrom = $now->modify(\sprintf('-%d days', self::RECENT_IMPORT_DAYS));
        $issues = $snapshot->importIssues;
        // By weight (failed and cancelled, then rejected rows, then day gaps), newest first within a kind (#1092).
        usort($issues, static fn (ImportIssue $a, ImportIssue $b): int => [self::weight($a->kind), $b->uploadedAt] <=> [self::weight($b->kind), $a->uploadedAt]);

        $findings = [];
        foreach ($issues as $issue) {
            if ($issue->uploadedAt < $recentFrom) {
                continue;
            }

            $failedOrCancelled = ImportIssueKind::Failed === $issue->kind || ImportIssueKind::Cancelled === $issue->kind;
            // A later successful upload supersedes a failed or cancelled one.
            if ($failedOrCancelled && $snapshot->lastUploadAt instanceof \DateTimeImmutable && $snapshot->lastUploadAt > $issue->uploadedAt) {
                continue;
            }

            $amount = ImportIssueKind::DayGaps === $issue->kind ? $this->notableGapDays($issue) : $issue->amount;
            if (ImportIssueKind::DayGaps === $issue->kind && 0 === $amount) {
                continue;
            }

            $type = match ($issue->kind) {
                ImportIssueKind::Failed => DataStatusFindingType::ImportFailed,
                ImportIssueKind::Cancelled => DataStatusFindingType::ImportCancelled,
                ImportIssueKind::Rejects => DataStatusFindingType::ImportRejects,
                ImportIssueKind::DayGaps => DataStatusFindingType::ImportDayGaps,
            };
            $findings[] = new DataStatusFinding($type, amount: $amount, importId: $issue->importId, periodStart: $issue->uploadedAt);
        }

        return $findings;
    }

    private static function weight(ImportIssueKind $kind): int
    {
        return match ($kind) {
            ImportIssueKind::Failed, ImportIssueKind::Cancelled => 0,
            ImportIssueKind::Rejects => 1,
            ImportIssueKind::DayGaps => 2,
        };
    }

    /**
     * Days in the gaps of an import that are long enough for its volume, see {@see self::DAY_GAP_MIN_EXPECTED_RECORDS}.
     */
    private function notableGapDays(ImportIssue $issue): int
    {
        $days = 0;
        foreach ($issue->gapDays as $gapDays) {
            if ($issue->recordsPerDay * (float) $gapDays >= self::DAY_GAP_MIN_EXPECTED_RECORDS) {
                $days += $gapDays;
            }
        }

        return $days;
    }

    /**
     * @return list<DataStatusFinding>
     */
    private function notUpdatedFindings(HospitalDataSnapshot $snapshot, \DateTimeImmutable $now): array
    {
        $last = $snapshot->lastAllocationAt;
        if (!$last instanceof \DateTimeImmutable) {
            return [];
        }

        $days = (int) $last->setTime(0, 0)->diff($now->setTime(0, 0))->days;
        if ($last > $now || $days <= self::NOT_UPDATED_AFTER_DAYS) {
            return [];
        }

        return [new DataStatusFinding(DataStatusFindingType::NotUpdated, amount: $days, periodStart: $last)];
    }

    /**
     * A fully covered month in the window whose volume deviates from the median of up to twelve earlier fully
     * covered months by more than the threshold. Partial, missing and current months are never compared.
     *
     * @param list<MonthStatus> $months
     *
     * @return list<DataStatusFinding>
     */
    private function volumeChangeFindings(array $months, \DateTimeImmutable $windowStart): array
    {
        $findings = [];
        $baseline = [];
        foreach ($months as $status) {
            if (!$status->state->isFullyCovered()) {
                continue;
            }

            if ($status->month >= $windowStart && \count($baseline) >= self::VOLUME_MIN_BASELINE_MONTHS) {
                $median = $this->median($baseline);
                if ($median > 0) {
                    $change = ((float) $status->allocations - $median) / $median;
                    if (abs($change) > self::VOLUME_CHANGE_THRESHOLD) {
                        $findings[] = new DataStatusFinding(
                            DataStatusFindingType::VolumeChange,
                            amount: (int) round(abs($change) * 100.0),
                            periodStart: $status->month,
                            direction: $change > 0 ? 'up' : 'down',
                        );
                    }
                }
            }

            $baseline[] = $status->allocations;
            if (\count($baseline) > self::VOLUME_BASELINE_MONTHS) {
                array_shift($baseline);
            }
        }

        return $findings;
    }

    /**
     * @param list<int> $values
     */
    private function median(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? (float) ($values[$middle - 1] + $values[$middle]) / 2.0 : (float) $values[$middle];
    }
}
