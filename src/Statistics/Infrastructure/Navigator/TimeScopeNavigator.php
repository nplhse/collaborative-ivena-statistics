<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Navigator;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\Period;

/** @psalm-suppress ClassMustBeFinal */
class TimeScopeNavigator
{
    /**
     * @return array{
     *   prev: array{key:string, label:string, hint:string}|null,
     *   next: array{key:string, label:string, hint:string}|null
     * }
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

    /**
     * @return array{key:string, label:string, hint:string}|null
     */
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

    /**
     * @return array{key:string, label:string, hint:string}
     */
    private function year(\DateTimeImmutable $d, int $dir): array
    {
        $target = $d->modify($dir > 0 ? '+1 year' : '-1 year');

        return [
            'key' => $target->format('Y-01-01'),
            'label' => 'Year',
            'hint' => $target->format('Y'),
        ];
    }

    /**
     * @return array{key:string, label:string, hint:string}
     */
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

    /**
     * @return array{key:string, label:string, hint:string}
     */
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

    /**
     * @return array{key:string, label:string, hint:string}
     */
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

    /**
     * @return array{key:string, label:string, hint:string}
     */
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
