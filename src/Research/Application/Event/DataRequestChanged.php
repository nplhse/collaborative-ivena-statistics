<?php

declare(strict_types=1);

namespace App\Research\Application\Event;

/**
 * Timeline events and comments of a data extract request were stored (ADR 045). Dispatched after the commit, so
 * listeners such as the notifications read the stored rows.
 */
final readonly class DataRequestChanged
{
    /**
     * @param list<int> $eventIds
     * @param list<int> $commentIds
     */
    public function __construct(
        public int $requestId,
        public array $eventIds,
        public array $commentIds,
    ) {
    }
}
