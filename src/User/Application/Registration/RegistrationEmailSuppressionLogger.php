<?php

declare(strict_types=1);

namespace App\User\Application\Registration;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RegistrationEmailSuppressionLogger
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        #[Autowire(service: 'monolog.logger')]
        private LoggerInterface $logger,
    ) {
    }

    public function log(string $email, ?string $clientIp, ?string $matchedSuffix): void
    {
        $this->logger->info('registration suppressed by blocked email domain', [
            'email_hash' => sha1(mb_strtolower(trim($email))),
            'client_ip_hash' => sha1($clientIp ?? 'unknown'),
            'matched_suffix' => $matchedSuffix,
        ]);
    }
}
