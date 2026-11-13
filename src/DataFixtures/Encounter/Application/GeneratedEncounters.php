<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * The visits generated for one hospital and month, ordered by matching anchor and case number, and
 * the allocations that have no visit (#1052).
 */
final readonly class GeneratedEncounters
{
    /**
     * @param list<SyntheticVisit> $visits
     * @param list<int>            $allocationsWithoutVisit allocation ids, ascending
     */
    public function __construct(
        public array $visits,
        public array $allocationsWithoutVisit,
    ) {
    }

    /**
     * @return array<string, int> number of visits per group, every group listed
     */
    public function countsByKind(): array
    {
        $counts = [];
        foreach (SyntheticVisitKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        foreach ($this->visits as $visit) {
            ++$counts[$visit->kind->value];
        }

        return $counts;
    }

    /**
     * The true links: allocation id per index of the linked visit in `visits`.
     *
     * @return array<int, int>
     */
    public function trueLinks(): array
    {
        $links = [];
        foreach ($this->visits as $index => $visit) {
            if (null !== $visit->allocationId) {
                $links[$index] = $visit->allocationId;
            }
        }

        return $links;
    }

    /**
     * @param \Closure(SyntheticVisit): bool $filter
     */
    public function count(\Closure $filter): int
    {
        return \count(array_filter($this->visits, $filter));
    }
}
