<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Psr\Clock\ClockInterface;

/**
 * How much synthetic operational data the fixtures generate.
 */
final readonly class FixtureVolume
{
    /**
     * @param array<string, int>      $hospitals                  active hospitals per size (Large, Medium, Small), or `any` for one hospital of any size
     * @param array<string, float>    $allocationsPerDay          average allocations per hospital and day, by hospital size
     * @param float                   $mciCasesPerHospitalAndYear expected MCI cases per active hospital and year
     * @param \DateTimeImmutable|null $until                      last day of the generated period; null means yesterday
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
    ) {
    }

    public function withSeed(int $seed): self
    {
        return new self($this->profile, $this->hospitals, $this->months, $this->allocationsPerDay, $this->scale, $this->mciCasesPerHospitalAndYear, $seed, $this->rebuildProjection, $this->until);
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
     * Expected allocations per day for a hospital of the given size (Small, Medium, Large).
     */
    public function dailyRate(string $size): float
    {
        return ($this->allocationsPerDay[$size] ?? $this->allocationsPerDay['Medium'] ?? 15.0) * $this->scale;
    }
}
