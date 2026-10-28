<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use Random\Randomizer;

/**
 * Plans how many allocations each hospital gets per day.
 *
 * The daily count varies around the hospital's rate (by size): fewer at the weekend, a little more in
 * winter, and random noise, so the totals look like real data and are never round numbers.
 */
final readonly class ImportBatchPlanner
{
    /** Monday = 1 … Sunday = 7. */
    private const array WEEKDAY_FACTOR = [1 => 1.06, 2 => 1.02, 3 => 1.0, 4 => 1.0, 5 => 1.03, 6 => 0.93, 7 => 0.9];

    /** Slightly more cases in winter, slightly fewer in late summer. */
    private const array MONTH_FACTOR = [1 => 1.06, 2 => 1.04, 3 => 1.02, 4 => 0.99, 5 => 0.98, 6 => 0.98, 7 => 0.97, 8 => 0.96, 9 => 0.98, 10 => 1.0, 11 => 1.01, 12 => 1.05];

    public function __construct(
        private RandomStreams $streams,
    ) {
    }

    /**
     * The counts of a hospital and month come from their own random stream, so they do not depend
     * on the other hospitals or months of the plan.
     *
     * @param list<Hospital> $hospitals
     *
     * @return list<ImportBatch>
     */
    public function plan(FixtureVolume $volume, array $hospitals, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $hospitals = array_values(array_filter($hospitals, static fn (Hospital $hospital): bool => $hospital->isParticipating()));
        usort($hospitals, static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        $batches = [];
        foreach ($hospitals as $hospital) {
            foreach (self::months($periodStart, $periodEnd) as $month) {
                $batches[] = $this->planMonth($volume, $hospital, $month, $periodEnd);
            }
        }

        return $batches;
    }

    /**
     * The days of one month up to the last day of the period, with their counts.
     *
     * @param string $month month as Y-m
     */
    public function planMonth(FixtureVolume $volume, Hospital $hospital, string $month, \DateTimeImmutable $lastDay): ImportBatch
    {
        $rate = $volume->dailyRate(($hospital->getSize() ?? HospitalSize::MEDIUM)->value);
        $random = $this->streams->stream($volume->seed, $hospital->getPublicIdString(), $month, RandomStreams::VOLUME);
        $first = self::firstDay($month);
        $last = min($first->modify('last day of this month'), \DateTimeImmutable::createFromInterface($lastDay)->setTime(0, 0));

        $dailyCounts = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $expected = $rate * self::WEEKDAY_FACTOR[(int) $day->format('N')] * self::MONTH_FACTOR[(int) $day->format('n')] * $volume->trendFactor($day);
            $dailyCounts[$day->format('Y-m-d')] = $this->noisy($expected, $random);
        }

        return new ImportBatch($hospital, $month, sprintf('IVENA allocations %s', $month), $dailyCounts);
    }

    /**
     * @return list<string> the months (Y-m) from the start until the end of the period
     */
    public static function months(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $months = [];
        for ($month = $periodStart->modify('first day of this month')->setTime(0, 0); $month <= $periodEnd; $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * @param string $month month as Y-m
     */
    public static function firstDay(string $month): \DateTimeImmutable
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
        if (false === $first || $first->format('Y-m') !== $month) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a month (YYYY-MM).', $month));
        }

        return $first;
    }

    /**
     * Normal approximation of a Poisson count around the expected value.
     */
    private function noisy(float $expected, Randomizer $random): int
    {
        if ($expected <= 0.0) {
            return 0;
        }

        $u1 = max(1e-12, $random->nextFloat());
        $u2 = $random->nextFloat();
        $gauss = sqrt(-2.0 * log($u1)) * cos(2.0 * \M_PI * $u2);

        return max(0, (int) round($expected + $gauss * sqrt($expected)));
    }
}
