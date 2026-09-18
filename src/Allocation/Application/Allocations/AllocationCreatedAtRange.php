<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

/**
 * Inclusive calendar dates for the UI, half-open DateTime bounds for SQL.
 */
final readonly class AllocationCreatedAtRange
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $toExclusive = null,
        public ?string $fromDate = null,
        public ?string $untilDate = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->from instanceof \DateTimeImmutable || $this->toExclusive instanceof \DateTimeImmutable;
    }
}
