<?php

declare(strict_types=1);

namespace App\Notification\UI\ViewModel;

/**
 * One inbox entry ready for rendering. Redacted entries carry generic texts and no link.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by Twig templates.
 */
final readonly class NotificationView
{
    /**
     * @param array<string, scalar|null> $parameters
     */
    public function __construct(
        public int $id,
        public string $translationDomain,
        public string $titleKey,
        public string $messageKey,
        public array $parameters,
        public bool $hasLink,
        public bool $isRead,
        public bool $isRedacted,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
