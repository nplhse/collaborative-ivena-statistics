<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

/**
 * The two files of an exported demo month and their row counts.
 */
final readonly class DemoMonthCsvFiles
{
    public function __construct(
        public string $month,
        public string $allocationsPath,
        public int $allocations,
        public string $closuresPath,
        public int $closures,
    ) {
    }
}
