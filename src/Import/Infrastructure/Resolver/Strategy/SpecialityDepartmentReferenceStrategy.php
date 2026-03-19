<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Speciality;
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
        if ([] !== $this->specialityIdByKey && [] !== $this->departmentIdByKey) {
            return;
        }

        if ([] === $this->specialityIdByKey) {
            foreach ($this->specialityRepo->findBy([], ['name' => 'ASC']) as $speciality) {
                $id = $speciality->getId();
                if (null === $id) {
                    throw new \DomainException('Speciality id must not be null.');
                }

                $key = $this->key((string) $speciality->getName());
                $this->specialityIdByKey[$key] = $id;
            }
        }

        if ([] === $this->departmentIdByKey) {
            foreach ($this->departmentRepo->findBy([], ['name' => 'ASC']) as $department) {
                $id = $department->getId();
                if (null === $id) {
                    throw new \DomainException('Department id must not be null.');
                }

                $key = $this->key((string) $department->getName());
                $this->departmentIdByKey[$key] = $id;
            }
        }
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

    private function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
