<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MailConfig
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(param: 'app.mailer_from')]
        public string $fromEmail,
        #[Autowire(param: 'app.title')]
        public string $fromName,
        #[Autowire(param: 'app.title')]
        public string $appName,
        #[Autowire(param: 'app.mailer_reply_to')]
        private string $replyTo,
    ) {
    }

    public function replyTo(): ?string
    {
        $replyTo = trim($this->replyTo);

        return '' === $replyTo ? null : $replyTo;
    }
}
