<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\Scope;
use App\Service\Statistics\Util\Period;

final class TimeScopeNavigator
{
    /**
     * Returns:
     * [
     *     'prev' => ['key' => '2020-01-01', 'label' => 'Year', 'hint' => '2020'],
     *     'next' => ['key' => '2022-01-01', 'label' => 'Year', 'hint' => '2022'],
     * ]
     */
    public function calculate(Scope $scope): array
    {
        $gran = $scope->granularity;
        $anchor = new \DateTimeImmutable($scope->periodKey);

        return [
            'prev' => $this->step($gran, $anchor, -1),
            'next' => $this->step($gran, $anchor, +1),
        ];
    }

    private function step(string $granularity, \DateTimeImmutable $anchor, int $dir): ?array
    {
        $dir = $dir < 0 ? -1 : 1;

        return match ($granularity) {
            Period::YEAR => $this->year($anchor, $dir),
            Period::QUARTER => $this->quarter($anchor, $dir),
            Period::MONTH => $this->month($anchor, $dir),
            Period::WEEK => $this->week($anchor, $dir),
            Period::DAY => $this->day($anchor, $dir),
            Period::ALL => null,
            default => null,
        };
    }

    private function year(\DateTimeImmutable $d, int $dir): array
    {
        $target = $d->modify($dir > 0 ? '+1 year' : '-1 year');

        return [
            'key' => $target->format('Y-01-01'),
            'label' => 'Year',
            'hint' => $target->format('Y'),
        ];
    }

    private function quarter(\DateTimeImmutable $d, int $dir): array
    {
        $m = (int) $d->format('n');
        $q = intdiv($m - 1, 3) + 1;
        $q += $dir;
        $y = (int) $d->format('Y');

        if (0 === $q) {
            $q = 4;
            --$y;
        }
        if (5 === $q) {
            $q = 1;
            ++$y;
        }

        $startMonth = 1 + 3 * ($q - 1);
        $t = $d->setDate($y, $startMonth, 1)->setTime(0, 0);

        return [
            'key' => $t->format('Y-m-01'),
            'label' => 'Quarter',
            'hint' => sprintf('Q%s %s', $q, $y),
        ];
    }

    private function month(\DateTimeImmutable $d, int $dir): array
    {
        $target = $d->modify($dir > 0 ? '+1 month' : '-1 month');
        $target = $target->setDate(
            (int) $target->format('Y'),
            (int) $target->format('n'),
            1
        )->setTime(0, 0);

        return [
            'key' => $target->format('Y-m-01'),
            'label' => 'Month',
            'hint' => $target->format('M Y'),
        ];
    }

    private function week(\DateTimeImmutable $d, int $dir): array
    {
        $weekday = (int) $d->format('N');
        $monday = $d->modify('-'.($weekday - 1).' days')->setTime(0, 0);
        $target = $monday->modify($dir > 0 ? '+7 days' : '-7 days');

        return [
            'key' => $target->format('Y-m-d'),
            'label' => 'Week',
            'hint' => sprintf('W%s %s', $target->format('W'), $target->format('Y')),
        ];
    }

    private function day(\DateTimeImmutable $d, int $dir): array
    {
        $t = $d->modify($dir > 0 ? '+1 day' : '-1 day')->setTime(0, 0);

        return [
            'key' => $t->format('Y-m-d'),
            'label' => 'Day',
            'hint' => $t->format('Y-m-d'),
        ];
    }
}
