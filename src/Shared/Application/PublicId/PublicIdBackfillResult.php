<?php

declare(strict_types=1);

namespace App\Shared\Application\PublicId;

final readonly class PublicIdBackfillResult
{
    /**
     * @param array<string, int> $updatedByTable
     * @param array<string, int> $remainingByTable
     */
    public function __construct(
        public array $updatedByTable,
        public array $remainingByTable,
        public bool $completed,
    ) {
    }
}
