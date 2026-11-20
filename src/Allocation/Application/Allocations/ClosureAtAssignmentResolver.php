<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

use App\Allocation\Application\Filter\ClosureAtAssignmentRule;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\ClosureAtAssignment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closure state of one allocation, using the same rule as the list filter (#571).
 */
final readonly class ClosureAtAssignmentResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function forAllocation(Allocation $allocation): ClosureAtAssignment
    {
        $value = $this->entityManager->createQueryBuilder()
            ->select(ClosureAtAssignmentRule::stateDql('a'))
            ->from(Allocation::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $allocation->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return ClosureAtAssignment::from((string) $value);
    }
}
