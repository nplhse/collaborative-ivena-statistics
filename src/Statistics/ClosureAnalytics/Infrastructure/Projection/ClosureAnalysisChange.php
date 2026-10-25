<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

/**
 * What an analysis rebuild of one hospital changed, for the incremental volume planner.
 */
final readonly class ClosureAnalysisChange
{
    /**
     * @param list<int>                                                 $changedEventIds created events and events whose intervals changed
     * @param list<int>                                                 $removedEventIds
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $changedRanges   old and new extents of changed and removed events; their closures block or influence neighbours
     */
    public function __construct(
        public array $changedEventIds,
        public array $removedEventIds,
        public array $changedRanges,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->changedEventIds && [] === $this->removedEventIds;
    }
}
