<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

/**
 * Resolved identity context for a usage event (consent-gated).
 *
 * @phpstan-type UsageEventContextShape array{
 *   allowed: bool,
 *   analyticsUserKey: ?string,
 *   visitorKey: ?string,
 *   sessionKey: ?string,
 *   userRole: ?string
 * }
 */
final readonly class UsageEventContext
{
    public function __construct(
        public bool $allowed,
        public ?string $analyticsUserKey = null,
        public ?string $visitorKey = null,
        public ?string $sessionKey = null,
        public ?string $userRole = null,
    ) {
    }

    public static function denied(): self
    {
        return new self(allowed: false);
    }
}
