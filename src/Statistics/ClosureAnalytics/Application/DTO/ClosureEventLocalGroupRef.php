<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureEventLocalGroupRef
{
    /**
     * @param list<string> $closureUnits
     */
    public function __construct(
        public string $sourceGroupId,
        public string $label,
        public ?string $eventKey,
        public int $intervalCount,
        public array $closureUnits,
        public bool $current,
    ) {
    }

    public function showSourceGroupId(): bool
    {
        return $this->label !== $this->sourceGroupId;
    }
}
