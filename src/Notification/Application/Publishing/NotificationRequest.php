<?php

declare(strict_types=1);

namespace App\Notification\Application\Publishing;

/**
 * What a producing context asks the notification system to deliver.
 *
 * Parameters and metadata must not contain raw data (ADR 016): only stable identifiers,
 * display names the recipients may see, counts and reason codes.
 */
final readonly class NotificationRequest
{
    /**
     * @param list<int>                  $recipientUserIds
     * @param string                     $deduplicationKey deterministic per business fact, e.g. "import.run_finished:{runId}"
     * @param array<string, scalar|null> $parameters       translation parameters
     * @param int|null                   $scopeReferenceId id of the referenced entity for the type's access scope (e.g. hospital id)
     * @param array<string, scalar|null> $metadata         reference type and id only
     *
     * @psalm-suppress PossiblyUnusedMethod Built by producing contexts from step 3b of ADR 016 on.
     */
    public function __construct(
        public string $type,
        public array $recipientUserIds,
        public string $deduplicationKey,
        public array $parameters = [],
        public ?NotificationAction $action = null,
        public ?int $scopeReferenceId = null,
        public array $metadata = [],
    ) {
    }
}
