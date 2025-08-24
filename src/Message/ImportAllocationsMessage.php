<?php

namespace App\Message;

final readonly class ImportAllocationsMessage
{
    public function __construct(
        public int $importId,
    ) {
    }
}
