<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

/**
 * Counted accesses of one calendar month or year (#950).
 */
final readonly class AccessPeriod
{
    public function __construct(
        /** `YYYY` for a year, `YYYY-MM` for a month. */
        public string $key,
        public \DateTimeImmutable $start,
        public int $authenticated,
        public int $anonymous,
        /** The period contains today, so it is not over yet. */
        public bool $isCurrent,
        /** Counting started inside this period, so its first days are not counted. */
        public bool $startsBeforeCounting,
    ) {
    }

    public function total(): int
    {
        return $this->authenticated + $this->anonymous;
    }

    public function isComplete(): bool
    {
        return !$this->isCurrent && !$this->startsBeforeCounting;
    }
}
