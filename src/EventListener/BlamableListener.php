<?php

namespace App\EventListener;

use App\Entity\Traits\Blamable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

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
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof UserInterface) {
            return;
        }

        if (!in_array(Blamable::class, $this->getTraitsForClass($entity), true)) {
            return;
        }

        if (method_exists($entity, 'getCreatedBy') && null !== $entity->getCreatedBy()) {
            return;
        }

        $entity->setCreatedBy($currentUser);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof UserInterface) {
            return;
        }

        if (!in_array(Blamable::class, $this->getTraitsForClass($entity), true)) {
            return;
        }

        $entity->setUpdatedBy($currentUser);
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
}
