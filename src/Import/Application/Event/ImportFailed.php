<?php

declare(strict_types=1);

namespace App\Import\Application\Event;

final class ImportFailed
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        public int $importId,
        public ?string $reason = null,
    ) {
    }
}
