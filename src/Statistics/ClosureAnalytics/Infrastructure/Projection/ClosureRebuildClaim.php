<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

final readonly class ClosureRebuildClaim
{
    /**
     * @param list<int> $hospitalIds
     * @param list<int> $requestIds  claimed closure_rebuild_request rows, completed or released by the rebuild
     */
    public function __construct(
        public bool $all,
        public array $hospitalIds,
        public array $requestIds = [],
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

        return new self(false, $ids, $requestIds);
    }
}
