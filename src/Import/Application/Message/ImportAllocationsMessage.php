<?php

namespace App\Import\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('sync')]
final readonly class ImportAllocationsMessage
{
    public function __construct(
        public int $importId,
    ) {
    }
}
