<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Application\ReferenceCatalog\ReferenceNameKey;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Import\Application\Exception\ReferenceNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class SpecialityDepartmentReferenceStrategy
{
    /** @var array<string,int> */
    private array $specialityIdByKey = [];

    /** @var array<string,int> */
    private array $departmentIdByKey = [];

    public function __construct(
        private readonly SpecialityRepository $specialityRepo,
        private readonly DepartmentRepository $departmentRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function warm(): void
    {
        $this->specialityIdByKey = [];
        $this->departmentIdByKey = [];

        foreach ($this->specialityRepo->findBy([], ['name' => 'ASC']) as $speciality) {
            $id = $speciality->getId();
            if (null === $id) {
                throw new \DomainException('Speciality id must not be null.');
            }

            $this->remember($this->specialityIdByKey, $this->key((string) $speciality->getName()), $id, (string) $speciality->getName());
        }

        foreach ($this->em->getRepository(SpecialityAlias::class)->findBy([], ['name' => 'ASC']) as $alias) {
            $id = $alias->getSpeciality()->getId();
            if (null === $id) {
                throw new \DomainException('Speciality id must not be null.');
            }

            $this->remember($this->specialityIdByKey, $alias->getNormalizedName(), $id, $alias->getName());
        }

        foreach ($this->departmentRepo->findBy([], ['name' => 'ASC']) as $department) {
            $id = $department->getId();
            if (null === $id) {
                throw new \DomainException('Department id must not be null.');
            }

            $this->remember($this->departmentIdByKey, $this->key((string) $department->getName()), $id, (string) $department->getName());
        }

        foreach ($this->em->getRepository(DepartmentAlias::class)->findBy([], ['name' => 'ASC']) as $alias) {
            $id = $alias->getDepartment()->getId();
            if (null === $id) {
                throw new \DomainException('Department id must not be null.');
            }

            $this->remember($this->departmentIdByKey, $alias->getNormalizedName(), $id, $alias->getName());
        }
    }

    /**
     * @return array{speciality: Speciality, department: Department}
     */
    public function requirePair(string $specialityName, string $departmentName): array
    {
        if ([] === $this->specialityIdByKey || [] === $this->departmentIdByKey) {
            $this->warm();
        }

        $specialityKey = $this->key($specialityName);
        $specialityId = $this->specialityIdByKey[$specialityKey] ?? null;
        if ('' === $specialityKey || null === $specialityId) {
            throw ReferenceNotFoundException::forField('speciality', $specialityName);
        }

        $departmentKey = $this->key($departmentName);
        $departmentId = $this->departmentIdByKey[$departmentKey] ?? null;
        if ('' === $departmentKey || null === $departmentId) {
            throw ReferenceNotFoundException::forField('department', $departmentName);
        }

        /** @var Speciality $speciality */
        $speciality = $this->em->getReference(Speciality::class, $specialityId);
        /** @var Department $department */
        $department = $this->em->getReference(Department::class, $departmentId);

        return [
            'speciality' => $speciality,
            'department' => $department,
        ];
    }

    /**
     * @param object                 $entity                    must expose setSpeciality(), setDepartment(), setDepartmentWasClosed()
     * @param callable(?bool): ?bool $departmentWasClosedPolicy
     */
    public function apply(
        object $entity,
        ?string $specialityName,
        ?string $departmentName,
        ?bool $departmentWasClosed,
        callable $departmentWasClosedPolicy,
    ): void {
        $specialityKey = $this->key((string) $specialityName);
        if ('' !== $specialityKey) {
            $specialityId = $this->specialityIdByKey[$specialityKey] ?? null;
            if (null === $specialityId) {
                throw ReferenceNotFoundException::forField('speciality', $specialityName);
            }

            /** @var Speciality $specialityRef */
            $specialityRef = $this->em->getReference(Speciality::class, $specialityId);
            $entity->setSpeciality($specialityRef);
        }

        $departmentKey = $this->key((string) $departmentName);
        if ('' !== $departmentKey) {
            $departmentId = $this->departmentIdByKey[$departmentKey] ?? null;
            if (null === $departmentId) {
                throw ReferenceNotFoundException::forField('department', $departmentName);
            }

            /** @var Department $departmentRef */
            $departmentRef = $this->em->getReference(Department::class, $departmentId);
            $entity->setDepartment($departmentRef);
        }

        $entity->setDepartmentWasClosed($departmentWasClosedPolicy($departmentWasClosed));
    }

    /**
     * @param array<string, int> $map
     */
    private function remember(array &$map, string $key, int $id, string $label): void
    {
        if ('' === $key) {
            return;
        }

        if (isset($map[$key]) && $map[$key] !== $id) {
            throw new \DomainException(sprintf('Reference name "%s" resolves to more than one catalog row.', $label));
        }

        $map[$key] = $id;
    }

    private function key(string $name): string
    {
        return ReferenceNameKey::normalize($name);
    }
}
