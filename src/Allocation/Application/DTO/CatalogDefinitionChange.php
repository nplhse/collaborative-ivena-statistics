<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

final readonly class CatalogDefinitionChange
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $action,
        public ?string $actorLabel,
        public string $summary,
    ) {
    }
}
