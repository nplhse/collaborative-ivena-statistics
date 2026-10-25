<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * One event, population and metric. The segments are the hours the metric was closed in the
 * population, or the population's whole closure extent when the event did not close the metric.
 */
final readonly class ClosureVolumeAnchor
{
    public \DateTimeImmutable $startsAt;

    public \DateTimeImmutable $endsAt;

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments sorted, disjoint
     */
    public function __construct(
        public int $eventId,
        public int $hospitalId,
        public string $scope,
        public int $specialityId,
        public int $departmentId,
        public ClosureVolumeMetricKind $metric,
        public ClosureVolumeRelevance $relevance,
        public int $closedDepartmentCount,
        public array $segments,
    ) {
        if ([] === $segments) {
            throw new \InvalidArgumentException('A closure volume anchor needs at least one segment.');
        }
        $this->startsAt = $segments[0][0];
        $this->endsAt = $segments[\count($segments) - 1][1];
    }
}
