<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Infrastructure\Notification\ReactionReceivedNotification;
use App\Notification\Application\Contract\GroupedNotificationWriterInterface;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Takes the name of a deleted account out of reaction notifications, read ones included (#973). It runs before the
 * delete: if the delete fails afterwards, an entry has only lost its names.
 *
 * @psalm-suppress UnusedClass Registered as a Doctrine listener.
 */
#[AsDoctrineListener(event: Events::onFlush, connection: 'default')]
final readonly class ReactionActorRedactionListener
{
    public function __construct(
        // Lazy: this listener is built with the entity manager, the notification services only when a user is deleted.
        #[Autowire(service: GroupedNotificationWriterInterface::class, lazy: true)]
        private GroupedNotificationWriterInterface $notifications,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            $userId = $entity instanceof User ? $entity->getId() : null;
            if (null !== $userId) {
                $this->notifications->redactPerson(ReactionReceivedNotification::TYPE, $userId, ReactionReceivedNotification::WITHOUT_NAMES);
            }
        }
    }
}
