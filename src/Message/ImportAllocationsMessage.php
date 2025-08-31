<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('sync')]
final readonly class ImportAllocationsMessage
{
    public function __construct(
        public int $importId,
    ) {
    }
}
