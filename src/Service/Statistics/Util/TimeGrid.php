<?php

declare(strict_types=1);

namespace App\Service\Statistics\Util;

final class TimeGrid
{
    /**
     * @return array<int, array{label: string, periodKey: string, isTotal?: bool}>
     */
    public static function columns(string $granularity, \DateTimeImmutable $anchor): array
    {
        return match ($granularity) {
            Period::QUARTER => self::forQuarter((int) $anchor->format('Y'), (int) ceil((int) $anchor->format('n') / 3)),
            Period::MONTH => self::forMonth((int) $anchor->format('Y'), (int) $anchor->format('n')),
            Period::WEEK => self::forWeek($anchor),
            Period::DAY => [['label' => $anchor->format('Y-m-d'), 'periodKey' => $anchor->format('Y-m-d')]],
            Period::ALL => [['label' => 'All', 'periodKey' => Period::ALL_ANCHOR_DATE]],
            default => self::forYear((int) $anchor->format('Y')),
        };
    }

    /**
     * @return list<array{label:string, periodKey:string, isTotal?:true}>
     */
    private static function forYear(int $year): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $cols = [];
        for ($m = 1; $m <= 12; ++$m) {
            $cols[] = [
                'label' => $labels[$m - 1],
                'periodKey' => sprintf('%04d-%02d-01', $year, $m),
            ];
        }

        $cols[] = ['label' => 'Total', 'periodKey' => 'TOTAL', 'isTotal' => true];

        return $cols;
    }

    /**
     * @return list<array{label:string, periodKey:string, isTotal?:true}>
     */
    private static function forQuarter(int $year, int $q): array
    {
        $q = max(1, min(4, $q));
        $startMonth = 1 + 3 * ($q - 1);
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $cols = [];
        for ($m = $startMonth; $m < $startMonth + 3; ++$m) {
            $cols[] = [
                'label' => $labels[$m - 1],
                'periodKey' => sprintf('%04d-%02d-01', $year, $m),
            ];
        }

        $cols[] = ['label' => 'Total', 'periodKey' => 'TOTAL', 'isTotal' => true];

        return $cols;
    }

    /**
     * @return list<array{label:string, periodKey:string}>
     */
    private static function forMonth(int $year, int $month): array
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
        if (false === $dt) {
            throw new \RuntimeException(sprintf('Invalid date given for year=%d, month=%d', $year, $month));
        }

        $daysInMonth = (int) $dt->format('t');

        $cols = [];
        for ($d = 1; $d <= $daysInMonth; ++$d) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $cols[] = [
                'label' => (new \DateTimeImmutable($date))->format('d.m.'),
                'periodKey' => $date,
            ];
        }

        return $cols;
    }

    /**
     * @return list<array{label:string, periodKey:string}>
     */
    private static function forWeek(\DateTimeImmutable $anchor): array
    {
        // Monday-based
        $weekday = (int) $anchor->format('N'); // 1..7 (Mon=1)
        $monday = $anchor->modify(sprintf('-%d days', $weekday - 1));
        $cols = [];
        for ($i = 0; $i < 7; ++$i) {
            $d = $monday->modify(sprintf('+%d days', $i));
            $cols[] = [
                'label' => $d->format('D d.m.'),
                'periodKey' => $d->format('Y-m-d'),
            ];
        }

        return $cols;
    }
}
