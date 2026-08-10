<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Domain\Entity\State;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<State>
 */
final class StateRepository extends ServiceEntityRepository implements StateLookupInterface
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, State::class);
    }

    #[\Override]
    public function findById(int $id): ?State
    {
        $entity = $this->find($id);

        return $entity instanceof State ? $entity : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function findNamesByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id', 's.name')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = $row['name'];
        }

        return $names;
    }

    public function getListPaginator(SpecialityQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('(CASE WHEN s.updatedAt IS NOT NULL THEN s.updatedAt ELSE s.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $sortField = match ($queryParametersDTO->sortBy) {
                'id' => 's.id',
                'name' => 's.name',
                default => 's.name',
            };
            $qb->orderBy($sortField, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(s.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
