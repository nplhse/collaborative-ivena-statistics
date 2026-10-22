<?php

declare(strict_types=1);

namespace App\Import\Application\Event;

/** @psalm-suppress PossiblyUnusedProperty runId is read by notification subscribers (ADR 016) */
final class ImportCompleted
{
    /**
     * @param string|null $runId Null for runs started by legacy messages
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        public int $importId,
        public ?string $runId = null,
    ) {
    }
}
