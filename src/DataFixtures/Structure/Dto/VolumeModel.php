<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * How the allocation volume of a hospital follows its beds (ADR 022).
 *
 * The preset rate of the size stays the anchor; a hospital gets it multiplied by
 * (beds ÷ reference beds of its size) ^ elasticity and by a hospital factor drawn log-normally with
 * mean 1. So hospitals of one size differ, larger ones get more cases, and the preset totals stay
 * about the same.
 */
final readonly class VolumeModel
{
    /**
     * @param array<string, int> $referenceBeds  size → beds at which the bed factor is 1
     * @param float              $bedElasticity  0 ignores the beds, 1 makes volume proportional to them
     * @param float              $hospitalSpread standard deviation of the log of the hospital factor
     */
    public function __construct(
        public array $referenceBeds,
        public float $bedElasticity,
        public float $hospitalSpread,
    ) {
    }

    /**
     * The factor of the beds alone; 1 without beds.
     */
    public function bedFactor(string $size, ?int $beds): float
    {
        $reference = $this->referenceBeds[$size] ?? null;
        if (null === $beds || $beds <= 0 || null === $reference || $reference <= 0) {
            return 1.0;
        }

        return ((float) $beds / (float) $reference) ** $this->bedElasticity;
    }
}
