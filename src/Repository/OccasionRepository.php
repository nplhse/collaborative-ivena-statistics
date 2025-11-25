<?php

namespace App\Repository;

use App\DataTransferObjects\OccasionQueryParametersDTO;
use App\Entity\Occasion;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Occasion>
 */
final class OccasionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Occasion::class);
    }

    public function getListPaginator(OccasionQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('(CASE WHEN o.updatedAt IS NOT NULL THEN o.updatedAt ELSE o.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('o.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(o.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
