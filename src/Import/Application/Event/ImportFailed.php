<?php

declare(strict_types=1);

namespace App\Import\Application\Event;

use App\Import\Domain\Enum\ImportRunReasonCode;

/** @psalm-suppress PossiblyUnusedProperty runId and reasonCode are read by notification subscribers (ADR 016) */
final class ImportFailed
{
    /**
     * @param string|null $runId Null for runs started by legacy messages
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        public int $importId,
        public ?string $reason = null,
        public ?string $runId = null,
        public ?ImportRunReasonCode $reasonCode = null,
    ) {
    }
}
