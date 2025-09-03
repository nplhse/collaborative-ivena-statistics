<?php

namespace App\Service\Import\Mapping;

use App\Entity\Allocation;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\Import;
use App\Entity\State;
use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use App\Enum\AllocationUrgency;
use App\Repository\DispatchAreaRepository;
use App\Repository\StateRepository;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Exception\InvalidDateException;
use App\Service\Import\Exception\InvalidEnumException;
use App\Service\Import\Exception\ReferenceNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;

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
    /** @var array<string,int> map(normalized_name => id) */
    private array $dispatchIdByKey = [];

    /** @var array<string,int> map(normalized_name => id) */
    private array $stateIdByKey = [];

    public function __construct(
        private readonly DispatchAreaRepository $dispatchRepo,
        private readonly StateRepository $stateRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function warm(): void
    {
        if ([] === $this->dispatchIdByKey) {
            foreach ($this->dispatchRepo->findAll() as $d) {
                $k = $this->key((string) $d->getName());
                if ('' !== $k && null !== $d->getId()) {
                    $this->dispatchIdByKey[$k] = (int) $d->getId();
                }
            }
        }

        if ([] === $this->stateIdByKey) {
            foreach ($this->stateRepo->findAll() as $s) {
                $k = $this->key((string) $s->getName());
                if ('' !== $k && null !== $s->getId()) {
                    $this->stateIdByKey[$k] = (int) $s->getId();
                }
            }
        }
    }

    public function fromDto(AllocationRowDTO $dto, Import $import): Allocation
    {
        $hospital = $import->getHospital();

        if (null === $hospital) {
            throw new \LogicException('Import has no hospital assigned');
        }

        $state = $hospital->getState();

        if (null === $state) {
            throw new \LogicException('Hospital has no state assigned');
        }

        $importRef = $this->refImport($import);
        $hospitalRef = $this->refHospital($import);

        $dispatchRef = $this->refByName(
            field: 'dispatchArea',
            name: $dto->dispatchArea,
            idMap: $this->dispatchIdByKey,
            class: DispatchArea::class
        );

        $stateRef = $this->refByName(
            field: 'state',
            name: $state->getName(),
            idMap: $this->stateIdByKey,
            class: State::class
        );

        $allocation = new Allocation();

        $allocation->setHospital($hospitalRef);
        $allocation->setDispatchArea($dispatchRef);
        $allocation->setState($stateRef);
        $allocation->setImport($importRef);

        if (!\is_string($dto->createdAt) || '' === $dto->createdAt) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        try {
            $allocation->setCreatedAt(new \DateTimeImmutable($dto->createdAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        if (!\is_string($dto->arrivalAt) || '' === $dto->arrivalAt) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }

        try {
            $allocation->setArrivalAt(new \DateTimeImmutable($dto->arrivalAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }

        $allocation->setGender(
            AllocationGender::tryFrom((string) $dto->gender)
            ?? throw InvalidEnumException::forField('gender', (string) $dto->gender)
        );

        if (null !== $dto->transportType) {
            $allocation->setTransportType(
                AllocationTransportType::tryFrom($dto->transportType)
                ?? throw InvalidEnumException::forField('transportType', $dto->transportType)
            );
        }

        // Scalars / flags (already normalized by mapper)
        if (!\is_int($dto->age)) {
            throw new \LogicException('Age must be integer after validation');
        }

        if (!\is_int($dto->urgency)) {
            throw new \LogicException('Urgency must be integer after validation');
        }

        $allocation->setAge($dto->age);
        $allocation->setRequiresResus($dto->requiresResus ?? false);
        $allocation->setRequiresCathlab($dto->requiresCathlab ?? false);
        $allocation->setIsCPR($dto->isCPR ?? false);
        $allocation->setIsVentilated($dto->isVentilated ?? false);
        $allocation->setIsShock($dto->isShock ?? false);
        $allocation->setIsPregnant($dto->isPregnant ?? false);
        $allocation->setIsWithPhysician($dto->isWithPhysician ?? false);
        $allocation->setUrgency(AllocationUrgency::from($dto->urgency));

        return $allocation;
    }

    private function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
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

    /**
     * @template T of object
     *
     * @param array<string,int> $idMap
     * @param class-string<T>   $class Classname der Entity
     *
     * @return T
     *
     * @throws ORMException
     */
    private function refByName(string $field, ?string $name, array $idMap, string $class): object
    {
        $k = $this->key((string) $name);
        $id = $idMap[$k] ?? null;

        if (null === $id) {
            throw ReferenceNotFoundException::forField($field, $name);
        }

        /** @var T $ref */
        $ref = $this->em->getReference($class, $id);

        return $ref;
    }
}
