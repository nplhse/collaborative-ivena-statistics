<?php

declare(strict_types=1);

namespace App\Notification\Application\Delivery;

/**
 * The email of one delivery while it is being built. Starts as the generic notification email.
 *
 * @psalm-suppress PossiblyUnusedProperty type and parameters are there for customizers that need them.
 */
final class NotificationEmailContent
{
    /**
     * @param array<string, scalar|null> $parameters the stored notification parameters
     * @param array<string, mixed>       $context    template context
     * @param array<string, string>      $headers    additional text headers
     */
    public function __construct(
        public readonly string $type,
        public readonly string $locale,
        public readonly array $parameters,
        public string $subject,
        public string $htmlTemplate,
        public array $context,
        public array $headers = [],
    ) {
    }
}
