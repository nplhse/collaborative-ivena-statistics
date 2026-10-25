<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Deletes imports selected on the import list in the background (#720). The ids were checked when the
 * message was queued; the handler checks them again, because the imports may have changed since.
 */
#[AsMessage('async_priority_low')]
final readonly class DeleteImportsMessage
{
    /**
     * @param string    $operationId UUID of this bulk deletion; keys its summary notification
     * @param list<int> $importIds
     */
    public function __construct(
        public string $operationId,
        public int $initiatedByUserId,
        public array $importIds,
    ) {
    }
}
