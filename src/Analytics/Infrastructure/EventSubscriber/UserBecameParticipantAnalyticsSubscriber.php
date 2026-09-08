<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\EventSubscriber;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class UserBecameParticipantAnalyticsSubscriber
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UsageAnalytics $usageAnalytics,
        private UserRepository $userRepository,
    ) {
    }

    #[AsEventListener(event: UserBecameParticipant::class)]
    public function onUserBecameParticipant(UserBecameParticipant $event): void
    {
        $user = $this->userRepository->find($event->userId);
        if (!$user instanceof User) {
            return;
        }

        $this->usageAnalytics->recordForUser(
            UsageEventName::USER_BECAME_PARTICIPANT,
            $user,
            FeatureArea::Other,
        );
    }
}
