<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureRecurringProfilePage
{
    /**
     * @param list<ClosureRecurringProfileRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
