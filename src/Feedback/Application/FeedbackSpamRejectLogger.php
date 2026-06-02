<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FeedbackSpamRejectLogger
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        #[Autowire(service: 'monolog.logger')]
        private LoggerInterface $logger,
    ) {
    }

    public function logRejected(
        ?User $user,
        ?string $guestEmail,
        ?string $clientIp,
        ?FeedbackSpamCheckResult $spamDecision,
        string $rateLimiterHit,
    ): void {
        $this->logger->info('feedback submission rejected as spam', [
            'spam_score' => $spamDecision?->getScore(),
            'spam_reasons' => $spamDecision?->getReasons() ?? [],
            'submitter' => $this->resolveSubmitter($user, $guestEmail),
            'client_ip_hash' => $this->hashClientIp($clientIp),
            'is_authenticated' => $user instanceof User,
            'rate_limiter_hit' => '' !== $rateLimiterHit ? $rateLimiterHit : null,
        ]);
    }

    private function resolveSubmitter(?User $user, ?string $guestEmail): string
    {
        if ($user instanceof User) {
            $id = $user->getId();

            return null !== $id ? \sprintf('user:%d', $id) : 'user:unknown';
        }

        if (null !== $guestEmail && '' !== trim($guestEmail)) {
            return 'guest:'.sha1(mb_strtolower(trim($guestEmail)));
        }

        return 'guest:unknown';
    }

    private function hashClientIp(?string $clientIp): string
    {
        return sha1($clientIp ?? 'unknown');
    }
}
