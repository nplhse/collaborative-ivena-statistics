<?php

namespace App\Repository;

use App\DataTransferObjects\SpecialityQueryParametersDTO;
use App\Entity\Speciality;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Speciality>
 */
final class SpecialityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Speciality::class);
    }

    public function getListPaginator(SpecialityQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('(CASE WHEN s.updatedAt IS NOT NULL THEN s.updatedAt ELSE s.createdAt END) AS HIDDEN sortDate')
        ;

        $sortField = match ($queryParametersDTO->sortBy) {
            'lastChange' => 'sortDate',
            default => 's.'.$queryParametersDTO->sortBy,
        };

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(s.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
