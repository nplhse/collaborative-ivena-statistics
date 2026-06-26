<?php

declare(strict_types=1);

namespace App\Allocation\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async_priority_low')]
final readonly class BackfillAllocationsForIndicationRawMessage
{
    public function __construct(
        public int $indicationRawId,
    ) {
    }
}
