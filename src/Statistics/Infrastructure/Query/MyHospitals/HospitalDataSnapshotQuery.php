<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\MyHospitals;

use App\Allocation\Application\Contracts\HospitalDataSnapshotReaderInterface;
use App\Allocation\Application\DTO\MyHospitals\CoveredPeriod;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Application\DTO\MyHospitals\ImportIssueKind;
use App\Allocation\Application\DTO\MyHospitals\UploaderContribution;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Two queries for any number of hospitals: allocation imports from `import`, and monthly volumes from
 * `allocation_stats_projection` (by hospital, year and month). Projection rows count only when their import is
 * completed or partial, so failed imports never add allocations.
 */
final readonly class HospitalDataSnapshotQuery implements HospitalDataSnapshotReaderInterface
{
    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, HospitalDataSnapshot>
     */
    #[\Override]
    public function read(array $hospitalIds): array
    {
        $hospitalIds = array_values(array_unique($hospitalIds));
        if ([] === $hospitalIds) {
            return [];
        }

        $volumes = $this->monthlyVolumes($hospitalIds);
        $imports = $this->imports($hospitalIds);

        $snapshots = [];
        foreach ($hospitalIds as $hospitalId) {
            $snapshots[$hospitalId] = $this->snapshot($hospitalId, $volumes[$hospitalId] ?? [], $imports[$hospitalId] ?? []);
        }

        return $snapshots;
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, list<array{month: string, allocations: int, first_at: string, last_at: string}>>
     */
    private function monthlyVolumes(array $hospitalIds): array
    {
        // Grouped by the projection's year and month columns, not date_trunc(): Postgres has statistics for them,
        // expects a few groups and aggregates by hash instead of sorting every row (EXPLAIN on the prod mirror:
        // 80 hospitals, 2.3M rows, 3.3 s → 0.8 s; see my-hospitals-overview.md).
        /** @var list<array{hospital_id: int|string, created_year: int|string, created_month: int|string, allocations: int|string, first_at: string, last_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT p.hospital_id,
                   p.created_year,
                   p.created_month,
                   COUNT(*) AS allocations,
                   MIN(p.created_at) AS first_at,
                   MAX(p.created_at) AS last_at
            FROM allocation_stats_projection p
            INNER JOIN import i ON i.id = p.import_id
            WHERE p.hospital_id IN (:hospitalIds)
              AND i.status IN (:relevantStatuses)
            GROUP BY p.hospital_id, p.created_year, p.created_month
            ORDER BY p.hospital_id, p.created_year, p.created_month
            SQL,
            ['hospitalIds' => $hospitalIds, 'relevantStatuses' => $this->relevantStatuses()],
            ['hospitalIds' => ArrayParameterType::INTEGER, 'relevantStatuses' => ArrayParameterType::STRING],
        );

        $byHospital = [];
        foreach ($rows as $row) {
            $byHospital[(int) $row['hospital_id']][] = [
                'month' => \sprintf('%04d-%02d', (int) $row['created_year'], (int) $row['created_month']),
                'allocations' => (int) $row['allocations'],
                'first_at' => $row['first_at'],
                'last_at' => $row['last_at'],
            ];
        }

        return $byHospital;
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, list<array{id: int|string, hospital_id: int|string, status: string, created_at: string, created_by_id: int|string, rows_rejected: int|string|null, export_starts_at: string|null, export_ends_at: string|null, data_coverage: string|null}>>
     */
    private function imports(array $hospitalIds): array
    {
        /** @var list<array{id: int|string, hospital_id: int|string, status: string, created_at: string, created_by_id: int|string, rows_rejected: int|string|null, export_starts_at: string|null, export_ends_at: string|null, data_coverage: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT id, hospital_id, status, created_at, created_by_id, rows_rejected,
                   export_starts_at, export_ends_at, data_coverage
            FROM import
            WHERE hospital_id IN (:hospitalIds)
              AND type = :type
            ORDER BY created_at, id
            SQL,
            ['hospitalIds' => $hospitalIds, 'type' => ImportType::ALLOCATION->value],
            ['hospitalIds' => ArrayParameterType::INTEGER],
        );

        $byHospital = [];
        foreach ($rows as $row) {
            $byHospital[(int) $row['hospital_id']][] = $row;
        }

        return $byHospital;
    }

    /**
     * @param list<array{month: string, allocations: int, first_at: string, last_at: string}>                                                                                                                                                             $volumeRows
     * @param list<array{id: int|string, hospital_id: int|string, status: string, created_at: string, created_by_id: int|string, rows_rejected: int|string|null, export_starts_at: string|null, export_ends_at: string|null, data_coverage: string|null}> $importRows
     */
    private function snapshot(int $hospitalId, array $volumeRows, array $importRows): HospitalDataSnapshot
    {
        $monthlyVolumes = [];
        $allocationCount = 0;
        $firstAt = null;
        $lastAt = null;
        foreach ($volumeRows as $row) {
            $monthlyVolumes[$row['month']] = $row['allocations'];
            $allocationCount += $row['allocations'];
            $firstAt ??= $this->dateTime($row['first_at']);
            $lastAt = $this->dateTime($row['last_at']);
        }

        $counts = ['relevant' => 0, 'partial' => 0, 'failed' => 0, 'cancelled' => 0, 'pending' => 0];
        $coveredPeriods = [];
        $uploaders = [];
        $issues = [];
        $lastUploadAt = null;

        foreach ($importRows as $row) {
            $importId = (int) $row['id'];
            $uploadedAt = $this->dateTime($row['created_at']);
            $status = ImportStatus::tryFrom($row['status']);

            switch ($status) {
                case ImportStatus::FAILED:
                    ++$counts['failed'];
                    $issues[] = new ImportIssue($importId, ImportIssueKind::Failed, $uploadedAt, 0);

                    continue 2;
                case ImportStatus::CANCELLED:
                    ++$counts['cancelled'];
                    $issues[] = new ImportIssue($importId, ImportIssueKind::Cancelled, $uploadedAt, 0);

                    continue 2;
                case ImportStatus::PENDING:
                case ImportStatus::RUNNING:
                    ++$counts['pending'];

                    continue 2;
                case ImportStatus::COMPLETED:
                case ImportStatus::PARTIAL:
                    break;
                case null:
                    continue 2;
            }

            ++$counts['relevant'];
            if (!$lastUploadAt instanceof \DateTimeImmutable || $uploadedAt > $lastUploadAt) {
                $lastUploadAt = $uploadedAt;
            }

            $uploaderId = (int) $row['created_by_id'];
            $previous = $uploaders[$uploaderId] ?? null;
            $uploaders[$uploaderId] = new UploaderContribution(
                ($previous->importCount ?? 0) + 1,
                !$previous instanceof UploaderContribution || $uploadedAt > $previous->lastUploadAt ? $uploadedAt : $previous->lastUploadAt,
            );

            $rejected = null === $row['rows_rejected'] ? 0 : (int) $row['rows_rejected'];
            if (ImportStatus::PARTIAL === $status) {
                ++$counts['partial'];
                if ($rejected > 0) {
                    $issues[] = new ImportIssue($importId, ImportIssueKind::Rejects, $uploadedAt, $rejected);
                }
            }

            $coverage = $this->coverage($row['data_coverage']);
            $gapDays = $coverage?->daysWithoutData() ?? 0;
            if ($gapDays > 0) {
                $issues[] = new ImportIssue($importId, ImportIssueKind::DayGaps, $uploadedAt, $gapDays);
            }

            $period = $this->coveredPeriod($row['export_starts_at'], $row['export_ends_at'], $coverage);
            if ($period instanceof CoveredPeriod) {
                $coveredPeriods[] = $period;
            }
        }

        return new HospitalDataSnapshot(
            hospitalId: $hospitalId,
            allocationCount: $allocationCount,
            firstAllocationAt: $firstAt,
            lastAllocationAt: $lastAt,
            monthlyVolumes: $monthlyVolumes,
            coveredPeriods: $coveredPeriods,
            relevantImports: $counts['relevant'],
            partialImports: $counts['partial'],
            failedImports: $counts['failed'],
            cancelledImports: $counts['cancelled'],
            pendingImports: $counts['pending'],
            lastUploadAt: $lastUploadAt,
            uploaders: $uploaders,
            importIssues: $issues,
        );
    }

    /**
     * The export period of the import, or the days with records when no period is stored. A period ending exactly
     * at midnight does not cover that day (same rule as the import's data coverage).
     */
    private function coveredPeriod(?string $startsAt, ?string $endsAt, ?ImportDataCoverage $coverage): ?CoveredPeriod
    {
        if (null !== $startsAt && null !== $endsAt) {
            $start = $this->dateTime($startsAt);
            $end = $this->dateTime($endsAt);
            $lastDay = $end->setTime(0, 0);
            if ($end > $start && '00:00:00' === $end->format('H:i:s')) {
                $lastDay = $lastDay->modify('-1 day');
            }

            return $lastDay < $start->setTime(0, 0) ? null : new CoveredPeriod($start->setTime(0, 0), $lastDay);
        }

        $firstDay = $coverage?->firstDay();
        $lastDay = $coverage?->lastDay();
        if (!$firstDay instanceof \DateTimeImmutable || !$lastDay instanceof \DateTimeImmutable) {
            return null;
        }

        return new CoveredPeriod($firstDay, $lastDay);
    }

    private function coverage(?string $json): ?ImportDataCoverage
    {
        if (null === $json) {
            return null;
        }

        try {
            return ImportDataCoverage::fromArray(json_decode($json, true, 512, \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    private function dateTime(string $value): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!'.self::DATE_TIME_FORMAT, substr($value, 0, 19));
        if (!$dateTime instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException(\sprintf('Unexpected timestamp "%s".', $value));
        }

        return $dateTime;
    }

    /**
     * @return list<string>
     */
    private function relevantStatuses(): array
    {
        return [ImportStatus::COMPLETED->value, ImportStatus::PARTIAL->value];
    }
}
