<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

use App\Notification\Domain\Enum\NotificationAccessScope;

/**
 * One inbox entry as stored, before visibility rules are applied.
 */
final readonly class InboxEntry
{
    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, scalar>      $actionParameters
     */
    public function __construct(
        public int $id,
        public string $translationDomain,
        public string $titleKey,
        public string $messageKey,
        public array $parameters,
        public ?string $actionRoute,
        public array $actionParameters,
        public NotificationAccessScope $accessScope,
        public ?int $scopeReferenceId,
        public ?string $requiredRole,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $readAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt instanceof \DateTimeImmutable;
    }
}
