<?php

declare(strict_types=1);

namespace App\Import\Domain\ValueObject;

/**
 * Consecutive days without records inside the covered period of an import, both days inclusive.
 * A potential completeness issue, not a confirmed error.
 */
final readonly class ImportDataCoverageGap
{
    public function __construct(
        public \DateTimeImmutable $firstDay,
        public \DateTimeImmutable $lastDay,
    ) {
        if ($lastDay < $firstDay) {
            throw new \InvalidArgumentException('A coverage gap must not end before it starts.');
        }
    }

    public function days(): int
    {
        return (int) $this->firstDay->diff($this->lastDay)->days + 1;
    }
}
