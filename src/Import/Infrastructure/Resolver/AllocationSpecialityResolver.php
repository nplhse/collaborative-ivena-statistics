<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\ReferenceNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationSpecialityResolver implements AllocationEntityResolverInterface
{
    /** @var array<string,int> speciality_key => id */
    private array $specialityIdByKey = [];

    /** @var array<string,int> department_key => id */
    private array $departmentIdByKey = [];

    public function __construct(
        private readonly SpecialityRepository $specialityRepo,
        private readonly DepartmentRepository $departmentRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        if ([] === $this->specialityIdByKey) {
            foreach ($this->specialityRepo->findBy([], ['name' => 'ASC']) as $s) {
                $k = self::key((string) $s->getName());
                $this->specialityIdByKey[$k] = (int) $s->getId();
            }
        }

        if ([] === $this->departmentIdByKey) {
            foreach ($this->departmentRepo->findBy([], ['name' => 'ASC']) as $d) {
                $k = self::key((string) $d->getName());
                $this->departmentIdByKey[$k] = (int) $d->getId();
            }
        }
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $specKey = self::key((string) $dto->speciality);

        if ('' !== $specKey) {
            $id = $this->specialityIdByKey[$specKey] ?? null;
            if (null === $id) {
                throw ReferenceNotFoundException::forField('speciality', $dto->speciality);
            }
            /** @var Speciality $ref */
            $ref = $this->em->getReference(Speciality::class, $id);
            $entity->setSpeciality($ref);
        }

        $deptKey = self::key((string) $dto->department);

        if ('' !== $deptKey) {
            $id = $this->departmentIdByKey[$deptKey] ?? null;
            if (null === $id) {
                throw ReferenceNotFoundException::forField('department', $dto->department);
            }
            /** @var Department $ref */
            $ref = $this->em->getReference(Department::class, $id);
            $entity->setDepartment($ref);
        }

        $entity->setDepartmentWasClosed($dto->departmentWasClosed ?? false);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
