<?php

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @psalm-suppress ClassMustBeFinal
 *
 * @extends ServiceEntityRepository<IndicationRaw>
 */
class IndicationRawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationRaw::class);
    }

    public function getListPaginator(IndicationQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('i.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(i.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    /**
     * @return array<int,array{hash:string,id:int,normalized_id:int}>
     */
    public function preloadAllLight(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.id as id, r.hash AS hash, IDENTITY(r.normalized) AS normalized_id')
            ->getQuery()
            ->getArrayResult();
    }
}
