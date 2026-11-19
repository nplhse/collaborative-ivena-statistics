<?php

declare(strict_types=1);

namespace App\Kpi\Application\Contract;

/**
 * Sums of the daily KPI rows over a period of Berlin calendar days, for the admin KPI row.
 * Periods are half-open: `$from` is the first day, `$toExclusive` the first day after the period.
 */
interface KpiPeriodReaderInterface
{
    /**
     * Totals of the global rows; days without a row count as zero.
     *
     * @return array{importsCount: int, recordsTotal: int, recordsRejected: int}
     */
    public function globalTotals(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array;

    /**
     * Hospitals that participate today and had at least one successful import in the period.
     */
    public function countActiveParticipatingHospitals(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int;

    public function countParticipatingHospitals(): int;
}
