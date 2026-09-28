<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Batches closure-interval writes. Separate from the allocation persister so the
 * two import branches do not share one batch counter.
 */
final class DoctrineClosureIntervalPersister
{
    private int $count = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $batchSize = 250,
    ) {
    }

    public function persist(object $entity): void
    {
        $this->em->persist($entity);
        ++$this->count;

        if ($this->count >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->em->flush();
        $this->clear();
    }

    public function clear(): void
    {
        $this->em->clear();
        $this->count = 0;
    }
}
