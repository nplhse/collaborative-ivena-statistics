<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackClassification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Re-checks existing normal feedback with the current spam rules (issue #964, step 6).
 *
 * Only signals that can be read from stored data count: page context (`route_name`), links, HTML,
 * keywords, patterns, blocked link domains, token-only messages, length, and earlier entries with the same
 * guest email or message within the history window before the entry. Submission time and honeypots
 * were never stored, so they count as unknown, not as a signal. Entries that reach the threshold are
 * moved into quarantine with their reasons plus `reclassified`; the run is reviewed by whoever applies it,
 * so they are not reported in the weekly summary. Missing message fingerprints are filled in on apply.
 */
final readonly class FeedbackReclassifier
{
    public const string REASON = 'reclassified';

    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedbackSpamChecker $checker,
        private ClockInterface $clock,
        #[Autowire(param: 'app.feedback.spam.history_days')]
        private int $historyDays,
    ) {
    }

    /**
     * @return list<FeedbackReclassification> normal entries the current rules would quarantine; changes
     *                                        nothing unless $apply is true
     */
    public function reclassify(bool $apply): array
    {
        $entries = $this->entityManager->getRepository(Feedback::class)->findBy([], ['id' => 'ASC']);
        $now = $this->clock->now();

        /** @var array<string, list<int>> $emailTimes */
        $emailTimes = [];
        /** @var array<string, list<int>> $fingerprintTimes */
        $fingerprintTimes = [];
        $results = [];

        foreach ($entries as $feedback) {
            $createdAt = $feedback->getCreatedAt()->getTimestamp();
            $since = $createdAt - $this->historyDays * 86400;
            $email = mb_strtolower(trim((string) $feedback->getGuestEmail()));
            $fingerprint = $feedback->getMessageFingerprint() ?? FeedbackMessageFingerprint::fromMessage((string) $feedback->getMessage());

            if ($apply && null === $feedback->getMessageFingerprint()) {
                $feedback->setMessageFingerprint($fingerprint);
            }

            if (FeedbackClassification::NORMAL === $feedback->getClassification()) {
                $check = $this->checker->check(
                    (string) $feedback->getMessage(),
                    null,
                    // Timing was never recorded: neither "too fast" nor "missing".
                    $createdAt - 86400,
                    $createdAt,
                    null !== $feedback->getSubmittedBy(),
                    null !== $feedback->getRouteName(),
                    new FeedbackSpamHistory(
                        '' === $email ? 0 : $this->countSince($emailTimes[$email] ?? [], $since),
                        null === $fingerprint ? 0 : $this->countSince($fingerprintTimes[$fingerprint] ?? [], $since),
                    ),
                );

                if (!$check->verdict->isAccept()) {
                    $id = (int) $feedback->getId();
                    $reasons = [...$check->getReasons(), self::REASON];
                    $results[] = new FeedbackReclassification($id, $feedback->getCreatedAt(), $check->getScore(), $reasons);
                    if ($apply) {
                        $feedback->quarantine($reasons, $check->getScore(), null, $now)->markQuarantineReported($now);
                    }
                }
            }

            if ('' !== $email) {
                $emailTimes[$email][] = $createdAt;
            }
            if (null !== $fingerprint) {
                $fingerprintTimes[$fingerprint][] = $createdAt;
            }
        }

        if ($apply) {
            $this->entityManager->flush();
        }

        return $results;
    }

    /** @param list<int> $times */
    private function countSince(array $times, int $since): int
    {
        return \count(array_filter($times, static fn (int $time): bool => $time >= $since));
    }
}
