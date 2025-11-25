<?php

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineAllocationPersister implements AllocationPersisterInterface
{
    private int $count = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $batchSize = 250,
    ) {
    }

    #[\Override]
    public function persist(object $entity): void
    {
        $this->em->persist($entity);
        ++$this->count;

        if ($this->count >= $this->batchSize) {
            $this->flush();
        }
    }

    #[\Override]
    public function flush(): void
    {
        $this->em->flush();
        $this->em->clear();
        $this->count = 0;
    }
}
