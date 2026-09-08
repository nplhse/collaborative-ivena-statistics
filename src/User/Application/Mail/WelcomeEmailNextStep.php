<?php

declare(strict_types=1);

namespace App\User\Application\Mail;

/** @psalm-suppress PossiblyUnusedProperty Consumed by welcome email templates and mailer context. */
final readonly class WelcomeEmailNextStep
{
    public function __construct(
        public string $titleKey,
        public string $descriptionKey,
        public string $route,
    ) {
    }
}
