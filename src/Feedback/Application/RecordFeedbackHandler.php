<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\Feedback\Application\Event\FeedbackReceived;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class RecordFeedbackHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
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
    ): void {
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
            ->setAppVersion($appVersion);

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        $feedbackId = $feedback->getId();
        if (null !== $feedbackId) {
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
