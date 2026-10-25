<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineAllocationPersister implements AllocationPersisterInterface
{
    private int $count = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportRunCommitGuard $commitGuard,
        private readonly ImportRunTelemetry $telemetry,
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
        $started = hrtime(true);
        $this->commitGuard->flush($this->em);
        $this->clear();
        $this->telemetry->addPersistTime(hrtime(true) - $started, true);
    }

    #[\Override]
    public function clear(): void
    {
        $this->em->clear();
        $this->count = 0;
    }
}
