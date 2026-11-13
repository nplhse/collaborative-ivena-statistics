<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Allocation\Domain\Entity\Department;
use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Departments of the reference catalog by name, case-insensitive (ADR 020).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of DepartmentCatalogInterface.
 */
final readonly class DepartmentCatalogQuery implements DepartmentCatalogInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function findByName(string $name): ?Department
    {
        /** @var list<Department> $departments */
        $departments = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Department::class, 'd')
            ->where('LOWER(d.name) = :name')
            ->setParameter('name', mb_strtolower(trim($name)))
            ->orderBy('d.id', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        // A name that two catalog entries share is not a unique reference.
        return 1 === \count($departments) ? $departments[0] : null;
    }

    #[\Override]
    public function uniqueNames(): array
    {
        /** @var list<string> $names */
        $names = $this->entityManager->createQueryBuilder()
            ->select('d.name')
            ->from(Department::class, 'd')
            ->getQuery()
            ->getSingleColumnResult();

        $byKey = [];
        foreach ($names as $name) {
            $byKey[mb_strtolower($name)][] = $name;
        }

        $unique = [];
        foreach ($byKey as $entries) {
            if (1 === \count($entries)) {
                $unique[] = $entries[0];
            }
        }
        usort($unique, strcasecmp(...));

        return $unique;
    }
}
