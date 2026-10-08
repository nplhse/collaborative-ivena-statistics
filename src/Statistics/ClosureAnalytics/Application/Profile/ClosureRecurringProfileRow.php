<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureRecurringProfileRow
{
    /**
     * @param list<ClosureProfilePackedMember> $members
     * @param list<string>                     $specialities
     * @param list<string>                     $departments
     * @param list<string>                     $careLevels
     * @param list<string>                     $reasons
     */
    public function __construct(
        public int $hospitalId,
        public string $hospitalName,
        public string $profileKey,
        public int $eventCount,
        public array $members,
        public array $specialities,
        public array $departments,
        public array $careLevels,
        public array $reasons,
    ) {
    }

    public function once(): bool
    {
        return 1 === $this->eventCount;
    }

    public function profileQuery(): string
    {
        return ClosureProfileRef::group($this->hospitalId, $this->profileKey)->toQuery();
    }
}
