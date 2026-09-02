<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Activity;

use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Infrastructure\Repository\UserActivityRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserActivityRecorderInterface. */
#[AsAlias(UserActivityRecorderInterface::class)]
final readonly class DoctrineUserActivityRecorder implements UserActivityRecorderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserActivityRepository $activityRepository,
    ) {
    }

    #[\Override]
    public function record(UserActivityWrite $write): bool
    {
        if ($write->userId < 1 || '' === $write->deduplicationKey) {
            return false;
        }

        if ($this->activityRepository->existsWithDeduplicationKey($write->deduplicationKey)) {
            return false;
        }

        $user = $this->userRepository->find($write->userId);
        if (!$user instanceof User) {
            return false;
        }

        $activity = new UserActivity(
            user: $user,
            type: $write->type,
            occurredAt: $write->occurredAt,
            deduplicationKey: $write->deduplicationKey,
            metadata: $write->metadata,
        );

        $this->entityManager->persist($activity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    #[\Override]
    public function sync(UserActivityWrite $write): bool
    {
        if ($write->userId < 1 || '' === $write->deduplicationKey) {
            return false;
        }

        $existing = $this->activityRepository->findOneByDeduplicationKey($write->deduplicationKey);
        if ($existing instanceof UserActivity) {
            $existing->syncSnapshot($write->occurredAt, $write->metadata);
            $this->entityManager->flush();

            return true;
        }

        return $this->record($write);
    }
}
