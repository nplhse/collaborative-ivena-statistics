<?php

declare(strict_types=1);

namespace App\Install\Application\Environment;

final readonly class EnvironmentCheckReport
{
    /**
     * @param list<EnvironmentCheckItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function hasFailures(): bool
    {
        return array_any($this->items, static fn (EnvironmentCheckItem $item): bool => EnvironmentCheckStatus::Fail === $item->status);
    }

    public function hasWarnings(): bool
    {
        return array_any($this->items, static fn (EnvironmentCheckItem $item): bool => EnvironmentCheckStatus::Warn === $item->status);
    }
}
