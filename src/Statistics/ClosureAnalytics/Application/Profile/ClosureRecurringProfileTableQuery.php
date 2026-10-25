<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureRecurringProfileTableQuery
{
    /** @var list<string> */
    public const array SORT_KEYS = ['eventCount', 'hospital', 'configuration'];

    public function __construct(
        public string $sortBy,
        public string $orderBy,
        public string $profileQ,
    ) {
    }
}
