<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

/**
 * Storage details of the latest measurement for the Admin health card (never shown on the public `/health`).
 */
final readonly class StorageHealthDetailsDto
{
    public function __construct(
        public ?string $used,
        public ?string $capacity,
        public string $capacitySourceLabelKey,
        public ?float $remainingPercent,
        public string $levelLabelKey,
        public \DateTimeImmutable $measuredAt,
    ) {
    }
}
