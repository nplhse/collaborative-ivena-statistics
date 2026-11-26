<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Application\Event\ReactionChanged;
use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\ReactionType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Sets, replaces or removes a user's reaction (#467): a user has at most one reaction per target; choosing another
 * one replaces it, choosing the current one again removes it.
 */
final readonly class ReactionService
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private ReactionTargetRegistry $targets,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws ReactionTargetUnavailableException when the target does not exist or the user may not see it
     */
    public function toggle(User $user, ReactionTarget $target, ReactionType $type): void
    {
        $provider = $this->targets->get($target->type);
        if (!$provider instanceof ReactionTargetProviderInterface || [] === $provider->visibleIds([$target->id], $user)) {
            throw new ReactionTargetUnavailableException(\sprintf('The reaction target %s is not available.', $target->key()));
        }

        $reaction = $this->entityManager->getRepository(Reaction::class)->findOneBy([
            'user' => $user,
            'targetType' => $target->type,
            'targetId' => $target->id,
        ]);
        $previous = $reaction?->getType();
        $now = $this->clock->now();

        if ($reaction instanceof Reaction && $previous === $type) {
            $this->entityManager->remove($reaction);
            $current = null;
        } elseif ($reaction instanceof Reaction) {
            $reaction->change($type, $now);
            $current = $type;
        } else {
            $this->entityManager->persist(new Reaction($user, $target->type, $target->id, $type, $now));
            $current = $type;
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A second request of the same user created the reaction in the meantime (double click); it wins.
            return;
        }

        $userId = $user->getId();
        if (null !== $userId) {
            $this->eventDispatcher->dispatch(new ReactionChanged($target->type, $target->id, $userId, $previous, $current));
        }
    }
}
