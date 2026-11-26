<?php

declare(strict_types=1);

namespace App\Research\Application\Message;

/**
 * Runs the export of a release in the worker (ADR 045). Handling it twice is harmless: only a queued run is taken over.
 */
final readonly class RunDataExportMessage
{
    public function __construct(
        public int $runId,
    ) {
    }
}
