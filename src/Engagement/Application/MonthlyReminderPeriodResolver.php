<?php

declare(strict_types=1);

namespace App\Engagement\Application;

final readonly class MonthlyReminderPeriodResolver
{
    private const string TIMEZONE = 'Europe/Berlin';

    /**
     * @return array{
     *     referenceDate: \DateTimeImmutable,
     *     reportingYear: int,
     *     reportingMonth: int,
     *     reportingMonthStart: \DateTimeImmutable,
     *     reportingMonthEnd: \DateTimeImmutable,
     *     uploadYear: int,
     *     uploadMonth: int,
     *     chartStart: \DateTimeImmutable,
     *     chartMonthKeys: list<string>,
     *     chartLabels: list<string>,
     * }
     */
    public function resolve(?\DateTimeImmutable $referenceDate = null): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $reference = ($referenceDate ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz);

        $currentMonthStart = $reference->modify('first day of this month 00:00:00');
        $reportingMonthStart = $currentMonthStart->modify('-1 month');
        $reportingYear = (int) $reportingMonthStart->format('Y');
        $reportingMonth = (int) $reportingMonthStart->format('n');
        $reportingMonthEnd = $reportingMonthStart->modify('+1 month');

        $chartStart = $currentMonthStart->modify('-11 months');
        $chartMonthKeys = [];
        $chartLabels = [];
        $cursor = $chartStart;
        while ($cursor <= $currentMonthStart) {
            $chartMonthKeys[] = $cursor->format('Y-m');
            $chartLabels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        return [
            'referenceDate' => $reference,
            'reportingYear' => $reportingYear,
            'reportingMonth' => $reportingMonth,
            'reportingMonthStart' => $reportingMonthStart,
            'reportingMonthEnd' => $reportingMonthEnd,
            'uploadYear' => (int) $currentMonthStart->format('Y'),
            'uploadMonth' => (int) $currentMonthStart->format('n'),
            'chartStart' => $chartStart,
            'chartMonthKeys' => $chartMonthKeys,
            'chartLabels' => $chartLabels,
        ];
    }
}
