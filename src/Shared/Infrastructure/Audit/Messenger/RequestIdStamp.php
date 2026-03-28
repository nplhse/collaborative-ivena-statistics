<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(
        private string $requestId,
    ) {
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }
}
