<?php

declare(strict_types=1);

namespace App\Event;

final class ImportCompleted
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        public int $importId,
    ) {
    }
}
