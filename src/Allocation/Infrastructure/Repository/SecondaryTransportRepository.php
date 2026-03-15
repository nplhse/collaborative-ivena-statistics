<?php

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\UI\Http\DTO\SecondaryTransportQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecondaryTransport>
 */
final class SecondaryTransportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecondaryTransport::class);
    }

    public function getListPaginator(SecondaryTransportQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('st')
            ->addSelect('(CASE WHEN st.updatedAt IS NOT NULL THEN st.updatedAt ELSE st.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('st.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search && '' !== trim($queryParametersDTO->search)) {
            $qb->andWhere($qb->expr()->like('LOWER(st.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
