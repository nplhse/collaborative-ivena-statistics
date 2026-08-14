<?php

declare(strict_types=1);

namespace App\User\Infrastructure\EventSubscriber;

use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\UserActivityType;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: UserRegistered::class, method: 'onUserRegistered')]
final readonly class UserRegisteredActivitySubscriber
{
    public function __construct(
        private UserRepository $userRepository,
        private UserActivityRecorderInterface $activityRecorder,
    ) {
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        $user = $this->userRepository->find($event->userId);
        if (!$user instanceof User) {
            return;
        }

        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::JOINED,
            occurredAt: $user->getCreatedAt(),
            deduplicationKey: UserActivityDeduplicationKey::joined($event->userId),
        ));
    }
}
