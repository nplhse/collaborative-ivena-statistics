<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\Feedback\Domain\Entity\Feedback;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Reviewer decisions on the quarantine. Neither decision sends a notification: a released entry joins the
 * normal list and open count, a manually quarantined one leaves them.
 */
final readonly class FeedbackQuarantineReview
{
    public const string MANUAL_REASON = 'manual';

    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<Feedback> $entries
     *
     * @return int number of entries released (entries that are not quarantined are skipped)
     */
    public function release(iterable $entries, User $reviewer): int
    {
        $released = 0;
        $now = $this->clock->now();
        foreach ($entries as $feedback) {
            if (!$feedback->isQuarantined()) {
                continue;
            }

            $feedback->release($reviewer, $now);
            ++$released;
        }

        $this->entityManager->flush();

        return $released;
    }

    /**
     * Deletes quarantined entries for good; normal entries are skipped.
     *
     * @param iterable<Feedback> $entries
     */
    public function delete(iterable $entries): int
    {
        $deleted = 0;
        foreach ($entries as $feedback) {
            if (!$feedback->isQuarantined()) {
                continue;
            }

            $this->entityManager->remove($feedback);
            ++$deleted;
        }

        $this->entityManager->flush();

        return $deleted;
    }

    /** Moves missed spam into quarantine; an entry in quarantine already stays as it is. */
    public function quarantine(Feedback $feedback, User $reviewer): void
    {
        if ($feedback->isQuarantined()) {
            return;
        }

        $feedback->quarantine([self::MANUAL_REASON], null, $reviewer, $this->clock->now());
        $this->entityManager->flush();
    }
}
