<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

/**
 * What a dataset run would do for one hospital, found before anything is written (#877).
 */
final readonly class DatasetHospitalPlan
{
    /**
     * @param list<string> $blockers        why the hospital cannot be generated; empty when it is ready
     * @param list<string> $generate        months that get new data (Y-m)
     * @param list<string> $keep            complete generated months that stay (mode Fill)
     * @param list<string> $replace         months whose generated imports are deleted first (subset of $generate)
     * @param list<int>    $scenarioImports scenario imports of the hospital in the period that are deleted first
     * @param list<string> $notes           facts worth showing that do not block, such as another seed in kept months
     */
    public function __construct(
        public int $hospitalId,
        public string $name,
        public array $blockers,
        public array $generate = [],
        public array $keep = [],
        public array $replace = [],
        public array $scenarioImports = [],
        public int $plannedAllocations = 0,
        public string $profile = '',
        public array $notes = [],
    ) {
    }

    public function isReady(): bool
    {
        return [] === $this->blockers;
    }

    public function hasWork(): bool
    {
        return $this->isReady() && [] !== $this->generate;
    }
}
