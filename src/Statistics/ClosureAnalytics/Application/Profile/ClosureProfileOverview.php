<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileOverview
{
    /**
     * @param list<ClosureProfileListItem> $groups
     * @param list<ClosureProfileListItem> $specialities
     */
    public function __construct(
        public array $groups,
        public array $specialities,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups && [] === $this->specialities;
    }
}
