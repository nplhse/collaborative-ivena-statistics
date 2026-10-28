<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use Psr\Clock\ClockInterface;

/**
 * The whole months a dataset run generates, shared by all its hospitals (#877).
 */
final readonly class DatasetPeriod
{
    public const int MAX_MONTHS = 60;

    /**
     * @param string $from first month as Y-m
     * @param string $to   last month as Y-m
     */
    private function __construct(
        public string $from,
        public string $to,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a month is malformed, the order is wrong or the period is too long
     */
    public static function between(string $from, string $to): self
    {
        $first = ImportBatchPlanner::firstDay($from);
        $last = ImportBatchPlanner::firstDay($to);
        if ($first > $last) {
            throw new \InvalidArgumentException(\sprintf('The period starts after it ends (%s to %s).', $from, $to));
        }
        $period = new self($from, $to);
        if (\count($period->months()) > self::MAX_MONTHS) {
            throw new \InvalidArgumentException(\sprintf('A period has at most %d months.', self::MAX_MONTHS));
        }

        return $period;
    }

    /**
     * The demo window (#791): $months whole months up to the month before last. The last complete month stays
     * free for the demo CSV upload (`app:demo:csv`); on 2026-10-28 with 12 months: 2025-09 to 2026-08.
     */
    public static function demoDefault(ClockInterface $clock, int $months = 12): self
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('A period has at least one month.');
        }
        $to = \DateTimeImmutable::createFromInterface($clock->now())->modify('first day of this month')->setTime(0, 0)->modify('-2 months');

        return self::between($to->modify(\sprintf('-%d months', $months - 1))->format('Y-m'), $to->format('Y-m'));
    }

    /**
     * @return list<string> months as Y-m, in order
     */
    public function months(): array
    {
        return ImportBatchPlanner::months(ImportBatchPlanner::firstDay($this->from), ImportBatchPlanner::firstDay($this->to));
    }

    public function firstDay(): \DateTimeImmutable
    {
        return ImportBatchPlanner::firstDay($this->from);
    }

    /**
     * First day after the period.
     */
    public function end(): \DateTimeImmutable
    {
        return ImportBatchPlanner::firstDay($this->to)->modify('+1 month');
    }

    /**
     * The last day that gets data: the end of the period, or $limit (usually yesterday) when that is earlier.
     */
    public function lastDay(\DateTimeImmutable $limit): \DateTimeImmutable
    {
        return min($this->end()->modify('-1 day'), \DateTimeImmutable::createFromInterface($limit)->setTime(0, 0));
    }
}
