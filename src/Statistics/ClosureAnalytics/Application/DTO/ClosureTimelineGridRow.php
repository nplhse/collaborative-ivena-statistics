<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureTimelineGridRow
{
    /**
     * @param list<ClosureTimelineGridCell> $cells
     */
    public function __construct(
        public string $key,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public array $cells,
    ) {
    }
}
