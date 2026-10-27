<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\DataFixtures\FixtureVolume;

/**
 * One synthetic import per hospital and month, like real monthly IVENA uploads.
 */
final readonly class ImportBatch
{
    /**
     * @param array<string, int> $dailyCounts allocations per day (Y-m-d)
     */
    public function __construct(
        public Hospital $hospital,
        public string $importName,
        public array $dailyCounts,
    ) {
    }

    public function allocationCount(): int
    {
        return array_sum($this->dailyCounts);
    }
}

/**
 * Plans how many allocations each hospital gets per day.
 *
 * The daily count varies around the hospital's rate (by size): fewer at the weekend, a little more in
 * winter, and random noise, so the totals look like real data and are never round numbers.
 */
final class ImportBatchPlanner
{
    /** Monday = 1 … Sunday = 7. */
    private const array WEEKDAY_FACTOR = [1 => 1.06, 2 => 1.02, 3 => 1.0, 4 => 1.0, 5 => 1.03, 6 => 0.93, 7 => 0.9];

    /** Slightly more cases in winter, slightly fewer in late summer. */
    private const array MONTH_FACTOR = [1 => 1.06, 2 => 1.04, 3 => 1.02, 4 => 0.99, 5 => 0.98, 6 => 0.98, 7 => 0.97, 8 => 0.96, 9 => 0.98, 10 => 1.0, 11 => 1.01, 12 => 1.05];

    /**
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
            $rate = $volume->dailyRate(($hospital->getSize() ?? HospitalSize::MEDIUM)->value);
            $byMonth = [];
            for ($day = $periodStart->setTime(0, 0); $day <= $periodEnd; $day = $day->modify('+1 day')) {
                $expected = $rate * self::WEEKDAY_FACTOR[(int) $day->format('N')] * self::MONTH_FACTOR[(int) $day->format('n')];
                $byMonth[$day->format('Y-m')][$day->format('Y-m-d')] = $this->noisy($expected);
            }

            foreach ($byMonth as $month => $dailyCounts) {
                $batches[] = new ImportBatch($hospital, sprintf('IVENA allocations %s', $month), $dailyCounts);
            }
        }

        return $batches;
    }

    /**
     * Normal approximation of a Poisson count around the expected value.
     */
    private function noisy(float $expected): int
    {
        if ($expected <= 0.0) {
            return 0;
        }

        $u1 = max(1e-12, (float) mt_rand() / (float) mt_getrandmax());
        $u2 = (float) mt_rand() / (float) mt_getrandmax();
        $gauss = sqrt(-2.0 * log($u1)) * cos(2.0 * \M_PI * $u2);

        return max(0, (int) round($expected + $gauss * sqrt($expected)));
    }
}
