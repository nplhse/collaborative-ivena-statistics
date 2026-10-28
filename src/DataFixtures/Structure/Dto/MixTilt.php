<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * The case mix of one hospital relative to its segment: factors on the weights of indication
 * categories (first PZC digit), of age bands from 65 on and of urgency 1. A factor of 1 changes
 * nothing; the weights are renormalised when they are drawn.
 */
final readonly class MixTilt
{
    /** Age bands that start at this age or later count as old. */
    public const int OLD_AGE = 65;

    /**
     * @param array<int, float> $categories first PZC digit → factor
     */
    public function __construct(
        public array $categories = [],
        public float $oldAge = 1.0,
        public float $urgent = 1.0,
    ) {
    }

    public function category(string $code): float
    {
        return $this->categories[(int) substr($code, 0, 1)] ?? 1.0;
    }

    /**
     * @return array{categories: array<int, float>, old_age: float, urgent: float}
     */
    public function toArray(): array
    {
        return ['categories' => $this->categories, 'old_age' => $this->oldAge, 'urgent' => $this->urgent];
    }
}
