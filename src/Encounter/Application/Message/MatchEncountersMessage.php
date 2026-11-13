<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Runs the matcher for the allocations of one hospital on the given days (ADR 030, #1053): after an
 * encounter import, an allocation import, or the deletion or reprocess of either.
 */
#[AsMessage('async_priority_low')]
final readonly class MatchEncountersMessage
{
    /**
     * @param list<string> $days           allocation arrival days `Y-m-d`
     * @param int|null     $ignoreImportId import whose rows are being deleted; left out even if the run starts before that commit
     */
    public function __construct(
        public int $hospitalId,
        public array $days,
        public ?int $ignoreImportId = null,
    ) {
    }
}
