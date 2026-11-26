<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Comparison;

/**
 * Reference hospitals a viewer may choose in a scope (#1346) and how many of them at most.
 */
final readonly class ReferenceCandidates
{
    /**
     * @param array<int, string> $names hospital name by id, sorted by name
     */
    public function __construct(
        public array $names = [],
        public int $maxReferences = 0,
    ) {
    }

    public function isAvailable(): bool
    {
        return [] !== $this->names && $this->maxReferences > 0;
    }

    /**
     * The submitted references that are candidates, distinct and at most $maxReferences.
     *
     * @param list<int> $references
     *
     * @return list<int>
     */
    public function restrict(array $references): array
    {
        $allowed = array_values(array_filter(
            array_values(array_unique($references)),
            fn (int $id): bool => isset($this->names[$id]),
        ));

        return \array_slice($allowed, 0, max(0, $this->maxReferences));
    }
}
