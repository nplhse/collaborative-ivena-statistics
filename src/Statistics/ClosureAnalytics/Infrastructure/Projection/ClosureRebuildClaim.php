<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

final readonly class ClosureRebuildClaim
{
    /**
     * @param list<int>                                                                  $hospitalIds
     * @param list<int>                                                                  $requestIds  claimed closure_rebuild_request rows, completed or released by the rebuild
     * @param array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null> $ranges      changed ranges per hospital; null (or a missing hospital) means the whole hospital
     */
    public function __construct(
        public bool $all,
        public array $hospitalIds,
        public array $requestIds = [],
        public array $ranges = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(false, []);
    }

    public function isEmpty(): bool
    {
        return !$this->all && [] === $this->hospitalIds;
    }

    /**
     * Changed ranges of one hospital, or null when the whole hospital has to be rebuilt.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    public function rangesFor(int $hospitalId): ?array
    {
        if ($this->all) {
            return null;
        }

        return $this->ranges[$hospitalId] ?? null;
    }

    public function merge(self $other): self
    {
        if ($this->isEmpty() && [] === $this->requestIds) {
            return $other;
        }
        if ($other->isEmpty() && [] === $other->requestIds) {
            return $this;
        }
        $requestIds = array_values(array_unique([...$this->requestIds, ...$other->requestIds]));
        sort($requestIds);
        if ($this->all || $other->all) {
            return new self(true, [], $requestIds);
        }
        $ids = array_values(array_unique([...$this->hospitalIds, ...$other->hospitalIds]));
        sort($ids);
        $ranges = [];
        foreach ($ids as $hospitalId) {
            $left = $this->scopeOf($hospitalId);
            $right = $other->scopeOf($hospitalId);
            if (false === $left) {
                $ranges[$hospitalId] = false === $right ? null : $right;
            } elseif (false === $right) {
                $ranges[$hospitalId] = $left;
            } else {
                $ranges[$hospitalId] = null === $left || null === $right ? null : [...$left, ...$right];
            }
        }

        return new self(false, $ids, $requestIds, $ranges);
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|false|null false when this claim does not contain the hospital
     */
    private function scopeOf(int $hospitalId): array|false|null
    {
        if (!\in_array($hospitalId, $this->hospitalIds, true)) {
            return false;
        }

        return $this->rangesFor($hospitalId);
    }
}
