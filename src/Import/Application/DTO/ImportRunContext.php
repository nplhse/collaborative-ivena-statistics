<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Component\Uid\Uuid;

/**
 * The run a message handler is processing.
 */
final readonly class ImportRunContext
{
    /**
     * @param bool $resumed True when the run was already running, i.e. Messenger redelivered the message after an error
     */
    public function __construct(
        public int $importId,
        public Uuid $runId,
        public bool $resumed = false,
    ) {
    }
}
