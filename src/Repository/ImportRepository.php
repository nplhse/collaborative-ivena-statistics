<?php

namespace App\Repository;

use App\DataTransferObjects\ListImportQueryParametersDTO;
use App\Entity\Hospital;
use App\Entity\Import;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Import>
 */
final class ImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Import::class);
    }

    public function getPaginator(ListImportQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
            ->leftJoin(
                Hospital::class,
                'h',
                Join::WITH,
                'i.hospital = h.id'
            )
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('i.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('i.name', ':search'))
                ->orWhere($qb->expr()->like('h.name', ':search'))
                ->setParameter('search', '%'.$queryParametersDTO->search.'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
