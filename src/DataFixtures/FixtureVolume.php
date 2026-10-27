<?php

declare(strict_types=1);

namespace App\DataFixtures;

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

    /**
     * Expected allocations per day for a hospital of the given size (Small, Medium, Large).
     */
    public function dailyRate(string $size): float
    {
        return ($this->allocationsPerDay[$size] ?? $this->allocationsPerDay['Medium'] ?? 15.0) * $this->scale;
    }
}
