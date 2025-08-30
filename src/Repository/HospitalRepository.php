<?php

namespace App\Repository;

use App\DataTransferObjects\HospitalQueryParametersDTO;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\State;
use App\Entity\User;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hospital>
 */
final class HospitalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hospital::class);
    }

    public function getQueryBuilderForAccessibleHospitals(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->orderBy('h.name', 'ASC');

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $qb;
        }

        return $qb
            ->innerJoin('h.owner', 'o')
            ->andWhere('o = :user')
            ->setParameter('user', $user->getId());
    }

    public function getHospitalListPaginator(HospitalQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('h')
            ->addSelect('(CASE WHEN h.updatedAt IS NOT NULL THEN h.updatedAt ELSE h.createdAt END) AS HIDDEN sortDate')
            ->leftJoin(
                State::class,
                's',
                Join::WITH,
                'h.state = s.id'
            )
            ->leftJoin(
                DispatchArea::class,
                'da',
                Join::WITH,
                'h.dispatchArea = da.id'
            )
        ;

        $field = match ($queryParametersDTO->sortBy) {
            'lastChange' => 'sortDate',
            'size' => 'h.beds',
            'dispatchArea' => 'da.name',
            'state' => 's.name',
            default => 'h.'.$queryParametersDTO->sortBy,
        };

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('h.name', ':search'))
                ->setParameter('search', '%'.$queryParametersDTO->search.'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
