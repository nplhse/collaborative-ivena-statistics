<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * How much synthetic operational data the fixtures generate.
 */
final readonly class FixtureVolume
{
    /**
     * @param array<string, float> $allocationsPerDay average allocations per hospital and day, by hospital size
     */
    public function __construct(
        public int $hospitalsActive,
        public int $months,
        public array $allocationsPerDay,
        public float $scale,
        public int $mciCases,
        public int $seed,
        public bool $rebuildProjection,
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
