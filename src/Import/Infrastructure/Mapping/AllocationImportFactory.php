<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ImportCreatedById;
use App\Import\Infrastructure\ReadOnlyAssociationReferencer;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * AllocationFactory.
 *
 * Converts a (validated & normalized) AllocationRowDTO into an Allocation entity.
 * - Hospital is taken from Import (single-hospital import).
 * - DispatchArea/State are resolved by name via pre-warmed in-memory maps (O(1)).
 * - Dates are expected as "d.m.Y H:i" strings in the DTO; parsing errors throw ImportExceptions.
 * - Enums are mapped via backed-enum tryFrom(); unknown values throw ImportExceptions.
 *
 * Call warm() once before the import loop to populate lookup caches.
 */
final readonly class AllocationImportFactory
{
    /**
     * @param iterable<AllocationEntityResolverInterface> $resolvers
     */
    public function __construct(
        private ReadOnlyAssociationReferencer $referencer,
        private ImportCreatedById $importCreatedById,
        #[AutowireIterator(tag: 'allocation.import_resolver')]
        private iterable $resolvers,
    ) {
    }

    public function warm(): void
    {
        foreach ($this->resolvers as $resolver) {
            $resolver->warm();
        }
    }

    public function fromDto(AllocationRowDTO $dto, Import $import): Allocation
    {
        $this->importCreatedById->captureFrom($import);

        $allocation = new Allocation();

        $hospitalRef = $this->refHospital($import);
        $allocation->setHospital($hospitalRef);

        $importRef = $this->refImport($import);
        $allocation->setImport($importRef);

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($allocation, $dto)) {
                $resolver->apply($allocation, $dto);
            }
        }

        return $allocation;
    }

    private function refImport(Import $import): Import
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new \LogicException('Import has no id assigned');
        }

        return $this->referencer->readOnlyReference(Import::class, $importId);
    }

    private function refHospital(Import $import): Hospital
    {
        $hospital = $import->getHospital();

        if (!$hospital instanceof Hospital) {
            throw new \LogicException('Import has no hospital assigned');
        }

        $hospitalId = $hospital->getId();
        if (null === $hospitalId) {
            throw new \LogicException('Import hospital has no id assigned');
        }

        return $this->referencer->readOnlyReference(Hospital::class, $hospitalId);
    }
}
