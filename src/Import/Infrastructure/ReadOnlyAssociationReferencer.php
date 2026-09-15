<?php

declare(strict_types=1);

namespace App\Import\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns a Doctrine association reference that cannot be flushed as an UPDATE.
 *
 * Used for Import/Hospital ghosts during allocation import so native lazy objects
 * cannot write NULL into NOT NULL columns if a getter initializes the ghost.
 */
final readonly class ReadOnlyAssociationReferencer
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function readOnlyReference(string $class, int $id): object
    {
        /** @var T $ref */
        $ref = $this->em->getReference($class, $id);
        $this->em->getUnitOfWork()->markReadOnly($ref);

        return $ref;
    }
}
