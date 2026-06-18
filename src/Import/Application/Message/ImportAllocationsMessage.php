<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async_priority_high')]
final readonly class ImportAllocationsMessage
{
    public function __construct(
        public int $importId,
    ) {
    }
}
