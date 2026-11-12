<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Query;

use App\Feedback\Application\Contract\FeedbackSpamHistoryLookupInterface;
use App\Feedback\Application\FeedbackSpamHistory;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Looks up earlier feedback (normal and quarantined) within the history window for the spam check.
 */
#[AsAlias(FeedbackSpamHistoryLookupInterface::class)]
final readonly class FeedbackSpamHistoryQuery implements FeedbackSpamHistoryLookupInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(param: 'app.feedback.spam.history_days')]
        private int $historyDays,
    ) {
    }

    #[\Override]
    public function lookup(?string $guestEmail, ?string $messageFingerprint): FeedbackSpamHistory
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', $this->historyDays))->format('Y-m-d H:i:s');

        $fromGuestEmail = 0;
        if (null !== $guestEmail && '' !== trim($guestEmail)) {
            $fromGuestEmail = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM feedback WHERE LOWER(guest_email) = :email AND created_at >= :since',
                ['email' => mb_strtolower(trim($guestEmail)), 'since' => $since],
            );
        }

        $sameMessage = 0;
        if (null !== $messageFingerprint) {
            $sameMessage = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM feedback WHERE message_fingerprint = :fingerprint AND created_at >= :since',
                ['fingerprint' => $messageFingerprint, 'since' => $since],
            );
        }

        return new FeedbackSpamHistory($fromGuestEmail, $sameMessage);
    }
}
