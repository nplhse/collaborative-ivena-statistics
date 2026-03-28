<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class ActorStamp implements StampInterface
{
    public function __construct(
        private ?int $userId,
    ) {
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
