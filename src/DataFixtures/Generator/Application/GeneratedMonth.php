<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

/**
 * What the generator wrote for one hospital and month.
 */
final readonly class GeneratedMonth
{
    public function __construct(
        public int $importId,
        public int $allocations,
        public int $mciCases,
    ) {
    }
}
