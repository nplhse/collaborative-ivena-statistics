<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileListItem
{
    public function __construct(
        public ClosureProfileKind $kind,
        public int $hospitalId,
        public string $hospitalName,
        public string $key,
        public string $title,
        public int $eventCount,
    ) {
    }

    public function once(): bool
    {
        return 1 === $this->eventCount;
    }

    public function ref(): ClosureProfileRef
    {
        return new ClosureProfileRef($this->kind, $this->hospitalId, $this->key);
    }
}
