<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

use App\User\Domain\Enum\UserActivityType;

final readonly class UserActivityWrite
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public int $userId,
        public UserActivityType $type,
        public \DateTimeImmutable $occurredAt,
        public string $deduplicationKey,
        public array $metadata = [],
    ) {
    }
}
