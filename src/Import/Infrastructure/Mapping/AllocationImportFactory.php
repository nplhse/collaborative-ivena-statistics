<?php

namespace App\Import\Infrastructure\Mapping;

use App\Entity\Allocation;
use App\Entity\Hospital;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;
use Doctrine\ORM\EntityManagerInterface;
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
final class AllocationImportFactory
{
    /**
     * @param iterable<AllocationEntityResolverInterface> $resolvers
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator(tag: 'allocation.import_resolver')]
        private readonly iterable $resolvers,
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
        /** @var Import $ref */
        $ref = $this->em->getReference(Import::class, $import->getId());

        return $ref;
    }

    private function refHospital(Import $import): Hospital
    {
        $hospital = $import->getHospital();

        if (null === $hospital) {
            throw new \LogicException('Import has no hospital assigned');
        }

        /** @var Hospital $ref */
        $ref = $this->em->getReference(Hospital::class, $hospital->getId());

        return $ref;
    }
}
