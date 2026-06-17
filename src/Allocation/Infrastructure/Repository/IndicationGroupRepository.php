<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\IndicationGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationGroup>
 */
final class IndicationGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationGroup::class);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function getDatalist(): array
    {
        /** @var list<array{id: int, name: string, category: ?string}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('g.id', 'g.name', 'g.category')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => $row['name'],
            ],
            $rows,
        );
    }

    /**
     * @return list<int>
     */
    public function getIndicationIds(int $groupId): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('i.id')
            ->innerJoin('g.indications', 'i')
            ->where('g.id = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
