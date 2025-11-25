<?php

namespace App\User\Infrastructure\Doctrine;

use App\Shared\Domain\Traits\Blamable;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/** @psalm-suppress UnusedClass */
#[AsDoctrineListener(event: Events::prePersist, priority: 500, connection: 'default')]
#[AsDoctrineListener(event: Events::preUpdate, priority: 500, connection: 'default')]
final readonly class BlamableListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $user = $this->getManagedUserOrNull($args);

        if (!$user instanceof UserInterface) {
            return;
        }

        if (!in_array(Blamable::class, $this->getTraitsForClass($entity), true)) {
            return;
        }

        if (method_exists($entity, 'getCreatedBy') && null !== $entity->getCreatedBy()) {
            return;
        }

        $entity->setCreatedBy($user);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $user = $this->getManagedUserOrNull($args);

        if (!$user instanceof UserInterface) {
            return;
        }

        if (!in_array(Blamable::class, $this->getTraitsForClass($entity), true)) {
            return;
        }

        $entity->setUpdatedBy($user);
    }

    /**
     * @return array<string, string>
     */
    private function getTraitsForClass(object $class): array
    {
        $usedTraits = \class_uses($class);

        if (false === $usedTraits) {
            return [];
        }

        return $usedTraits;
    }

    private function getManagedUserOrNull(PrePersistEventArgs|PreUpdateEventArgs $args): ?User
    {
        $rawUser = $this->security->getUser();

        if (!$rawUser instanceof User || null === $rawUser->getId()) {
            return null;
        }

        $em = $args->getObjectManager();

        /** @var User $managed */
        $managed = $em->getReference(User::class, $rawUser->getId());

        return $managed;
    }
}
