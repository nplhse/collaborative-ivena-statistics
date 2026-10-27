<?php

declare(strict_types=1);

namespace App\DataFixtures\Reset;

/**
 * One table the development reset empties (or, for hospital owners, clears a column of).
 */
final readonly class ResetStep
{
    public function __construct(
        public string $table,
        public string $kind,
        public string $action,
        public int $rows,
    ) {
    }
}
