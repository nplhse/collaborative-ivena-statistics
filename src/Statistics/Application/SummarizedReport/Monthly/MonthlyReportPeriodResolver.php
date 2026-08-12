<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly;

final readonly class MonthlyReportPeriodResolver
{
    private const string TIMEZONE = 'Europe/Berlin';

    /**
     * @return array{
     *     referenceDate: \DateTimeImmutable,
     *     year: int,
     *     month: int,
     *     monthStart: \DateTimeImmutable,
     *     monthEndExclusive: \DateTimeImmutable,
     *     previousMonthStart: \DateTimeImmutable,
     *     previousMonthEndExclusive: \DateTimeImmutable,
     *     yearAgoMonthStart: \DateTimeImmutable,
     *     yearAgoMonthEndExclusive: \DateTimeImmutable,
     *     latestCompletedMonthStart: \DateTimeImmutable,
     *     navigationPreviousYear: int,
     *     navigationPreviousMonth: int,
     *     navigationNextYear: ?int,
     *     navigationNextMonth: ?int,
     *     navigationNextEnabled: bool,
     * }
     */
    public function resolve(
        ?\DateTimeImmutable $referenceDate = null,
        ?int $year = null,
        ?int $month = null,
    ): array {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $reference = ($referenceDate ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz);

        $currentMonthStart = $reference->modify('first day of this month 00:00:00');
        $latestCompletedMonthStart = $currentMonthStart->modify('-1 month');

        $monthStart = $this->resolveMonthStart($latestCompletedMonthStart, $year, $month);
        $monthEndExclusive = $monthStart->modify('+1 month');
        $previousMonthStart = $monthStart->modify('-1 month');
        $yearAgoMonthStart = $monthStart->modify('-1 year');

        $navigationNextMonthStart = $monthStart->modify('+1 month');
        $navigationNextEnabled = $navigationNextMonthStart <= $latestCompletedMonthStart;

        return [
            'referenceDate' => $reference,
            'year' => (int) $monthStart->format('Y'),
            'month' => (int) $monthStart->format('n'),
            'monthStart' => $monthStart,
            'monthEndExclusive' => $monthEndExclusive,
            'previousMonthStart' => $previousMonthStart,
            'previousMonthEndExclusive' => $monthStart,
            'yearAgoMonthStart' => $yearAgoMonthStart,
            'yearAgoMonthEndExclusive' => $yearAgoMonthStart->modify('+1 month'),
            'latestCompletedMonthStart' => $latestCompletedMonthStart,
            'navigationPreviousYear' => (int) $previousMonthStart->format('Y'),
            'navigationPreviousMonth' => (int) $previousMonthStart->format('n'),
            'navigationNextYear' => $navigationNextEnabled ? (int) $navigationNextMonthStart->format('Y') : null,
            'navigationNextMonth' => $navigationNextEnabled ? (int) $navigationNextMonthStart->format('n') : null,
            'navigationNextEnabled' => $navigationNextEnabled,
        ];
    }

    private function resolveMonthStart(
        \DateTimeImmutable $latestCompletedMonthStart,
        ?int $year,
        ?int $month,
    ): \DateTimeImmutable {
        if (null === $year || null === $month || $month < 1 || $month > 12) {
            return $latestCompletedMonthStart;
        }

        $requested = $latestCompletedMonthStart
            ->setDate($year, $month, 1)
            ->setTime(0, 0);

        if ($requested > $latestCompletedMonthStart) {
            return $latestCompletedMonthStart;
        }

        return $requested;
    }
}
