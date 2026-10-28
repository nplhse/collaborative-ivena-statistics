<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * One hour piece. The rebuild shares a bucket between every anchor with the same hour ranges,
 * so its keys are formatted once instead of once per row.
 */
final class ClosureVolumeBucket
{
    private ?string $startKey = null;

    private ?string $endKey = null;

    public function __construct(
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly int $weekday,
        public readonly int $hour,
        public readonly int $dayTimeBucket,
        public readonly int $seconds,
    ) {
    }

    /**
     * Start as Y-m-d H:i:s.
     */
    public function startKey(): string
    {
        return $this->startKey ??= $this->start->format('Y-m-d H:i:s');
    }

    /**
     * End as Y-m-d H:i:s.
     */
    public function endKey(): string
    {
        return $this->endKey ??= $this->end->format('Y-m-d H:i:s');
    }
}
