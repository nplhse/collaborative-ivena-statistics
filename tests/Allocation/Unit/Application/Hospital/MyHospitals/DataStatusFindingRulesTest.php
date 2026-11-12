<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\DataStatusFinding;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Application\DTO\MyHospitals\ImportIssueKind;
use App\Allocation\Application\Hospital\MyHospitals\DataStatusFindingRules;
use App\Allocation\Application\Hospital\MyHospitals\MonthlyCoverageClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataStatusFindingRulesTest extends TestCase
{
    private const string NOW = '2026-11-12 09:30:00';

    private const string WINDOW_START = '2025-12-01';

    public function testRecentFailedImportWithoutLaterUploadIsReported(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            issues: [
                new ImportIssue(7, ImportIssueKind::Failed, new \DateTimeImmutable('2026-10-01 12:00:00'), 0),
                new ImportIssue(8, ImportIssueKind::Cancelled, new \DateTimeImmutable('2026-07-01 12:00:00'), 0), // older than 90 days
            ],
            lastUploadAt: '2026-09-15 12:00:00',
        );

        self::assertSame(['import_failed #7'], $this->describe($this->findings($snapshot)));
    }

    public function testLaterSuccessfulUploadSupersedesFailedImport(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            issues: [new ImportIssue(7, ImportIssueKind::Failed, new \DateTimeImmutable('2026-10-01 12:00:00'), 0)],
            lastUploadAt: '2026-10-02 08:00:00',
        );

        self::assertSame([], $this->findings($snapshot));
    }

    public function testRecentRejectsAndDayGapsAreReportedWithTheirAmount(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            issues: [
                new ImportIssue(3, ImportIssueKind::Rejects, new \DateTimeImmutable('2026-10-01 12:00:00'), 12),
                new ImportIssue(4, ImportIssueKind::DayGaps, new \DateTimeImmutable('2026-11-01 12:00:00'), 3),
            ],
            lastUploadAt: '2026-11-01 12:00:00',
        );

        // Newest first.
        self::assertSame(['import_day_gaps #4 3', 'import_rejects #3 12'], $this->describe($this->findings($snapshot)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function notUpdatedCases(): iterable
    {
        yield '89 days' => ['2026-08-15 23:00:00', false];
        yield '90 days' => ['2026-08-14 10:00:00', false];
        yield '91 days' => ['2026-08-13 10:00:00', true];
    }

    #[DataProvider('notUpdatedCases')]
    public function testNotUpdatedAfterNinetyDays(string $lastAllocationAt, bool $expected): void
    {
        $snapshot = SnapshotBuilder::snapshot(lastAllocationAt: $lastAllocationAt);

        $findings = $this->describe($this->findings($snapshot));

        self::assertSame($expected ? ['not_updated 91'] : [], $findings);
    }

    public function testGapsBecomeFindings(): void
    {
        $snapshot = SnapshotBuilder::snapshot(
            volumes: ['2026-01' => 1, '2026-05' => 1],
            periods: [['2026-01-01', '2026-01-31'], ['2026-05-01', '2026-05-31']],
            lastAllocationAt: '2026-10-31 10:00:00',
        );

        self::assertSame(['possible_gap 3 2026-02..2026-04'], $this->describe($this->findings($snapshot)));
    }

    public function testVolumeChangeAgainstMedianOfEarlierCoveredMonths(): void
    {
        // Baseline January to June 2026: 100, 100, 90, 110, 100, 100 → median 100.
        // July 160 (+60 %), August 120 (+20 %, below threshold), September 40 (-60 %).
        $snapshot = $this->monthlySnapshot([
            '2026-01' => 100, '2026-02' => 100, '2026-03' => 90, '2026-04' => 110, '2026-05' => 100, '2026-06' => 100,
            '2026-07' => 160, '2026-08' => 120, '2026-09' => 40,
        ], '2026-01-01', '2026-09-30');

        self::assertSame(['volume_change 60 up 2026-07', 'volume_change 60 down 2026-09'], $this->describe($this->findings($snapshot)));
    }

    public function testNoVolumeComparisonWithFewerThanSixBaselineMonths(): void
    {
        $snapshot = $this->monthlySnapshot([
            '2026-04' => 100, '2026-05' => 100, '2026-06' => 100, '2026-07' => 100, '2026-08' => 100, '2026-09' => 400,
        ], '2026-04-01', '2026-09-30');

        self::assertSame([], $this->findings($snapshot));
    }

    public function testPartialAndCurrentMonthsAreNeverCompared(): void
    {
        // October is covered until the 10th only and November is in progress: neither is a "drop".
        $snapshot = SnapshotBuilder::snapshot(
            volumes: [
                '2026-03' => 100, '2026-04' => 100, '2026-05' => 100, '2026-06' => 100, '2026-07' => 100, '2026-08' => 100,
                '2026-09' => 100, '2026-10' => 20, '2026-11' => 5,
            ],
            periods: [['2026-03-01', '2026-10-10']],
            lastAllocationAt: '2026-11-12 08:00:00',
        );

        self::assertSame([], $this->findings($snapshot));
    }

    /**
     * @param array<string, int> $volumes
     */
    private function monthlySnapshot(array $volumes, string $firstDay, string $lastDay): HospitalDataSnapshot
    {
        return SnapshotBuilder::snapshot(volumes: $volumes, periods: [[$firstDay, $lastDay]], lastAllocationAt: $lastDay.' 12:00:00');
    }

    /**
     * @return list<DataStatusFinding>
     */
    private function findings(HospitalDataSnapshot $snapshot): array
    {
        $now = new \DateTimeImmutable(self::NOW);
        $classifier = new MonthlyCoverageClassifier();
        $months = $classifier->classify($snapshot, $now);

        return new DataStatusFindingRules()->findings($snapshot, $months, $classifier->gaps($months), new \DateTimeImmutable(self::WINDOW_START), $now);
    }

    /**
     * @param list<DataStatusFinding> $findings
     *
     * @return list<string>
     */
    private function describe(array $findings): array
    {
        return array_map(static function (DataStatusFinding $finding): string {
            $parts = [$finding->type->value];
            if (null !== $finding->importId) {
                $parts[] = '#'.$finding->importId;
            }
            if (0 !== $finding->amount) {
                $parts[] = (string) $finding->amount;
            }
            if (null !== $finding->direction) {
                $parts[] = $finding->direction;
            }
            if (null === $finding->importId && $finding->periodStart instanceof \DateTimeImmutable && 'not_updated' !== $finding->type->value) {
                $parts[] = $finding->periodStart->format('Y-m').($finding->periodEnd instanceof \DateTimeImmutable ? '..'.$finding->periodEnd->format('Y-m') : '');
            }

            return implode(' ', $parts);
        }, $findings);
    }
}
