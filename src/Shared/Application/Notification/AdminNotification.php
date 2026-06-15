<?php

declare(strict_types=1);

namespace App\Shared\Application\Notification;

final readonly class AdminNotification
{
    /**
     * @param array<string, mixed> $templateContext
     */
    public function __construct(
        public AdminNotificationType $type,
        public array $templateContext,
        public ?string $referenceId = null,
    ) {
    }
}
