<?php

namespace App\Repository;

use App\DataTransferObjects\IndicationQueryParametersDTO;
use App\Entity\IndicationNormalized;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationNormalized>
 */
final class IndicationNormalizedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationNormalized::class);
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
}
