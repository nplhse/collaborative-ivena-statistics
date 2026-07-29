<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationGroup>
 */
final class IndicationGroupRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationGroup::class);
    }

    public function getListPaginator(SpecialityQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('g')
            ->addSelect('(CASE WHEN g.updatedAt IS NOT NULL THEN g.updatedAt ELSE g.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $sortField = match ($queryParametersDTO->sortBy) {
                'id' => 'g.id',
                'name' => 'g.name',
                default => 'g.name',
            };
            $qb->orderBy($sortField, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(g.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
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
                'id' => $row['id'],
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

        return array_map(static fn (array $row): int => $row['id'], $rows);
    }
}
