<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

final readonly class ClosureRebuildClaim
{
    /**
     * @param list<int> $hospitalIds
     */
    public function __construct(
        public bool $all,
        public array $hospitalIds,
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
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->all || $other->all) {
            return new self(true, []);
        }

        $ids = $this->hospitalIds;
        foreach ($other->hospitalIds as $hospitalId) {
            if (!\in_array($hospitalId, $ids, true)) {
                $ids[] = $hospitalId;
            }
        }
        sort($ids);

        return new self(false, $ids);
    }
}
