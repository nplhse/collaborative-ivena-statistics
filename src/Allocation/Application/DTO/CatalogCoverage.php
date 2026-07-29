<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

/** @psalm-immutable */
final readonly class CatalogCoverage
{
    /**
     * @param list<array{year: int, count: int}> $years
     */
    public function __construct(
        public int $allocationCount,
        public int $totalAllocationCount,
        public int $hospitalCount,
        public int $dispatchAreaCount,
        public int $stateCount,
        public ?\DateTimeImmutable $firstAt,
        public ?\DateTimeImmutable $lastAt,
        public array $years,
        public bool $suppressed,
    ) {
    }

    public function hasData(): bool
    {
        return $this->allocationCount > 0;
    }

    public function sharePercent(): ?float
    {
        if ($this->suppressed || $this->totalAllocationCount <= 0) {
            return null;
        }

        return round((100.0 * (float) $this->allocationCount) / (float) $this->totalAllocationCount, 2);
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, null, null, [], false);
    }
}
