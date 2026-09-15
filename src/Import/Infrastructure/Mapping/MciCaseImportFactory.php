<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Import\Application\Contracts\MciCaseEntityResolverInterface;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ImportCreatedById;
use App\Import\Infrastructure\ReadOnlyAssociationReferencer;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * MciCaseImportFactory.
 *
 * Converts a (validated & normalized) MciCaseRowDTO into an MciCase entity.
 * - Hospital/Import are taken from the Import (single-hospital import).
 * - Other fields are resolved via resolver chain (O(1) lookup maps).
 */
final readonly class MciCaseImportFactory
{
    /**
     * @param iterable<MciCaseEntityResolverInterface> $resolvers
     */
    public function __construct(
        private ReadOnlyAssociationReferencer $referencer,
        private ImportCreatedById $importCreatedById,
        #[AutowireIterator(tag: 'mci_case.import_resolver')]
        private iterable $resolvers,
    ) {
    }

    public function warm(): void
    {
        foreach ($this->resolvers as $resolver) {
            $resolver->warm();
        }
    }

    public function fromDto(MciCaseRowDTO $dto, Import $import): MciCase
    {
        $this->importCreatedById->captureFrom($import);

        $mciCase = new MciCase();

        $hospitalRef = $this->refHospital($import);
        $mciCase->setHospital($hospitalRef);

        $importRef = $this->refImport($import);
        $mciCase->setImport($importRef);

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($mciCase, $dto)) {
                $resolver->apply($mciCase, $dto);
            }
        }

        return $mciCase;
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
