<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Psr\Clock\ClockInterface;

/**
 * How much synthetic operational data the fixtures generate.
 */
final readonly class FixtureVolume
{
    /** The trend factor is 1 on this day; never change it, every generated day would change with it. */
    public const string TREND_REFERENCE_DAY = '2026-01-01';

    /**
     * @param array<string, int>      $hospitals                  active hospitals per size (Large, Medium, Small), or `any` for one hospital of any size
     * @param array<string, float>    $allocationsPerDay          average allocations per hospital and day, by hospital size
     * @param float                   $mciCasesPerHospitalAndYear expected MCI cases per active hospital and year
     * @param \DateTimeImmutable|null $until                      last day of the generated period; null means yesterday
     * @param float                   $trendPerYear               yearly growth of the daily rate, for example 0.02 for 2 %; 0 is off
     * @param array<string, float>    $scenarios                  scenarios the preset lays over its data: name → applications per year
     * @param float|null              $closureShare               share of closed department-hours for every hospital of the preset; null uses the shares of the structural patterns
     */
    public function __construct(
        public string $profile,
        public array $hospitals,
        public int $months,
        public array $allocationsPerDay,
        public float $scale,
        public float $mciCasesPerHospitalAndYear,
        public int $seed,
        public bool $rebuildProjection,
        public ?\DateTimeImmutable $until = null,
        public float $trendPerYear = 0.0,
        public array $scenarios = [],
        public ?float $closureShare = null,
    ) {
    }

    public function withSeed(int $seed): self
    {
        return new self($this->profile, $this->hospitals, $this->months, $this->allocationsPerDay, $this->scale, $this->mciCasesPerHospitalAndYear, $seed, $this->rebuildProjection, $this->until, $this->trendPerYear, $this->scenarios, $this->closureShare);
    }

    /**
     * How often the preset applies a scenario in its period: the yearly rate scaled to the months of the
     * period (with the current month), at least once if the rate is above zero.
     */
    public function scenarioCount(string $scenario): int
    {
        $perYear = $this->scenarios[$scenario] ?? 0.0;
        if ($perYear <= 0.0) {
            return 0;
        }

        return max(1, (int) round($perYear * (float) ($this->months + 1) / 12.0));
    }

    /**
     * The last generated day: `until`, or yesterday when no date is configured.
     */
    public function lastDay(ClockInterface $clock): \DateTimeImmutable
    {
        $lastDay = $this->until ?? $clock->now()->setTime(0, 0)->modify('-1 day');

        return \DateTimeImmutable::createFromInterface($lastDay)->setTime(0, 0);
    }

    /**
     * The yearly trend for a day, relative to a fixed reference day, so a month gets the same factor
     * whatever the last day of the period is.
     */
    public function trendFactor(\DateTimeImmutable $day): float
    {
        if (0.0 === $this->trendPerYear) {
            return 1.0;
        }

        $years = ((float) $day->getTimestamp() - (float) new \DateTimeImmutable(self::TREND_REFERENCE_DAY)->getTimestamp()) / (365.25 * 86400.0);

        return (1.0 + $this->trendPerYear) ** $years;
    }

    /**
     * Expected allocations per day for a hospital of the given size (Small, Medium, Large).
     */
    public function dailyRate(string $size): float
    {
        return ($this->allocationsPerDay[$size] ?? $this->allocationsPerDay['Medium'] ?? 15.0) * $this->scale;
    }
}
