<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Allocation\Application\Contracts\HospitalDataSnapshotInvalidatorInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Invalidates the My Hospitals snapshot of a hospital whenever one of its allocation imports is created, deleted,
 * or changes a field the snapshot reads (status, period, coverage, rejects, uploader, hospital). This covers
 * uploads, every end of an import run (completed, partial, failed, cancelled), deletions and hospital overrides,
 * whoever triggers them. Progress updates while an import runs change none of these fields and are ignored.
 *
 * Hospitals are collected in onFlush and handed over in postFlush, after the changes were written.
 *
 * @psalm-suppress UnusedClass
 */
#[AsDoctrineListener(event: Events::onFlush, connection: 'default')]
#[AsDoctrineListener(event: Events::postFlush, connection: 'default')]
final class HospitalDataSnapshotInvalidationListener implements ResetInterface
{
    /** Fields of `import` that HospitalDataSnapshotQuery reads. */
    private const array SNAPSHOT_FIELDS = [
        'hospital',
        'status',
        'type',
        'rowsRejected',
        'exportStartsAt',
        'exportEndsAt',
        'dataCoverage',
        'createdAt',
        'createdBy',
    ];

    /** @var array<int, int> */
    private array $hospitalIds = [];

    public function __construct(
        private readonly HospitalDataSnapshotInvalidatorInterface $invalidator,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Import && ImportType::ALLOCATION === $entity->getType()) {
                $this->collect($entity->getHospital());
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Import && ImportType::ALLOCATION === $entity->getType()) {
                $this->collect($entity->getHospital());
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Import) {
                $this->collectUpdate($uow, $entity);
            }
        }
    }

    public function postFlush(): void
    {
        if ([] === $this->hospitalIds) {
            return;
        }

        $hospitalIds = array_values($this->hospitalIds);
        $this->hospitalIds = [];
        $this->invalidator->invalidate($hospitalIds);
    }

    #[\Override]
    public function reset(): void
    {
        $this->hospitalIds = [];
    }

    private function collectUpdate(UnitOfWork $uow, Import $import): void
    {
        $changeSet = $uow->getEntityChangeSet($import);
        if ([] === array_intersect(self::SNAPSHOT_FIELDS, array_keys($changeSet))) {
            return;
        }

        // An import that was or is an allocation import counts, for the hospital it had and the one it has.
        $wasAllocation = isset($changeSet['type']) ? ImportType::ALLOCATION === $changeSet['type'][0] : ImportType::ALLOCATION === $import->getType();
        if (!$wasAllocation && ImportType::ALLOCATION !== $import->getType()) {
            return;
        }

        $this->collect($import->getHospital());
        if (isset($changeSet['hospital'])) {
            $previous = $changeSet['hospital'][0];
            $this->collect($previous instanceof Hospital ? $previous : null);
        }
    }

    private function collect(?Hospital $hospital): void
    {
        $id = $hospital?->getId();
        if (\is_int($id)) {
            $this->hospitalIds[$id] = $id;
        }
    }
}
