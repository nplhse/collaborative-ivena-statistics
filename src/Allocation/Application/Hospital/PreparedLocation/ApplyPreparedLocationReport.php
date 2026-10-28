<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

/** @psalm-immutable */
final readonly class ApplyPreparedLocationReport
{
    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows rows of [field, current, prepared, status]
     */
    public function __construct(
        public bool $success,
        public bool $dryRun,
        public ?string $error = null,
        public array $rows = [],
        public bool $changed = false,
    ) {
    }
}
