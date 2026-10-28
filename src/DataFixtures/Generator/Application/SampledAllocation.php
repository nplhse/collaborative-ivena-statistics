<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;

/**
 * One sampled allocation of a month and whether its department was closed at that time.
 */
final readonly class SampledAllocation
{
    public function __construct(
        public SampledAllocationAttributes $attributes,
        public bool $departmentWasClosed,
    ) {
    }
}
