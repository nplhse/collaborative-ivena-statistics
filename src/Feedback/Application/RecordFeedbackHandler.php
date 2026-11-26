<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\Feedback\Application\Event\FeedbackReceived;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Stores a submission that passed the spam check. Quarantined entries are classified before they are
 * stored and never dispatch {@see FeedbackReceived}, so they cannot trigger an immediate notification.
 */
final readonly class RecordFeedbackHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function execute(
        FeedbackCategory $category,
        string $message,
        ?string $guestEmail,
        ?User $submittedBy,
        string $pageUrl,
        ?string $routeName,
        ?array $context,
        ?string $userAgent,
        ?string $appVersion,
        FeedbackSpamCheckResult $spamCheck,
    ): void {
        if ($spamCheck->isRejected()) {
            throw new \LogicException('Rejected feedback submissions are never stored.');
        }

        $pageUrl = $this->truncate($pageUrl, 2048);
        $userAgent = null !== $userAgent ? $this->truncate($userAgent, 4096) : null;
        $appVersion = null !== $appVersion ? $this->truncate($appVersion, 64) : null;

        $feedback = new Feedback()
            ->setCategory($category)
            ->setMessage($message)
            ->setGuestEmail($guestEmail)
            ->setSubmittedBy($submittedBy)
            ->setPageUrl($pageUrl)
            ->setRouteName($routeName)
            ->setContext($context)
            ->setUserAgent($userAgent)
            ->setAppVersion($appVersion)
            ->setMessageFingerprint(FeedbackMessageFingerprint::fromMessage($message));

        if ($spamCheck->isQuarantined()) {
            $reasons = $spamCheck->getReasons();
            $feedback->quarantine([] !== $reasons ? $reasons : ['spam_score'], $spamCheck->getScore(), null, $this->clock->now());
        }

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        $feedbackId = $feedback->getId();
        if (null !== $feedbackId && !$feedback->isQuarantined()) {
            $this->eventDispatcher->dispatch(new FeedbackReceived($feedbackId));
        }
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
