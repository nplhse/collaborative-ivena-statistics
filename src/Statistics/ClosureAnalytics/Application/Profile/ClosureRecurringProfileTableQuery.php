<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureRecurringProfileTableQuery
{
    /** @var list<string> */
    public const array SORT_KEYS = ['eventCount', 'hospital', 'configuration'];

    public function __construct(
        public int $page,
        public int $limit,
        public string $sortBy,
        public string $orderBy,
        public string $profileQ,
    ) {
    }

    public function offset(): int
    {
        return max(0, ($this->page - 1) * $this->limit);
    }
}
