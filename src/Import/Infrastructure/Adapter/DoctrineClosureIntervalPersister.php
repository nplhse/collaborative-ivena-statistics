<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
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
        private readonly ImportRunCommitGuard $commitGuard,
        private readonly ImportRunTelemetry $telemetry,
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
        $started = hrtime(true);
        $this->commitGuard->flush($this->em);
        $this->clear();
        $this->telemetry->addPersistTime(hrtime(true) - $started, true);
    }

    public function clear(): void
    {
        $this->em->clear();
        $this->count = 0;
    }
}
