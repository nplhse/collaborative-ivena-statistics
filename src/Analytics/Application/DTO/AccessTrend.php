<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

/**
 * Long-term counted accesses (#950): total since the counting started, per year and per month.
 */
final readonly class AccessTrend
{
    /**
     * @param list<AccessPeriod> $years  oldest first, from the year counting started to the current year
     * @param list<AccessPeriod> $months oldest first, from the month counting started to the current month
     */
    public function __construct(
        /** First counted day (Europe/Berlin); null when nothing has been counted yet. */
        public ?\DateTimeImmutable $countingSince,
        public int $authenticated,
        public int $anonymous,
        public array $years,
        public array $months,
    ) {
    }

    public function total(): int
    {
        return $this->authenticated + $this->anonymous;
    }

    public function hasData(): bool
    {
        return $this->countingSince instanceof \DateTimeImmutable;
    }
}
