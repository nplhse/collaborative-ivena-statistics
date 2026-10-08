<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileEventLinks
{
    /**
     * @param list<ClosureProfileSpecialityLink> $specialities
     */
    public function __construct(
        public int $hospitalId,
        public ?string $groupKey,
        public array $specialities,
        public string $groupTitle = '',
    ) {
    }
}
