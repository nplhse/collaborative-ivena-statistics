<?php

namespace App\Repository;

use App\DataTransferObjects\AreaListQueryParametersDTO;
use App\Entity\DispatchArea;
use App\Entity\State;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DispatchArea>
 */
final class DispatchAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DispatchArea::class);
    }

    public function getAreaListPaginator(AreaListQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('da')
            ->addSelect('(CASE WHEN da.updatedAt IS NOT NULL THEN da.updatedAt ELSE da.createdAt END) AS HIDDEN sortDate')
            ->leftJoin(
                State::class,
                's',
                Join::WITH,
                'da.state = s.id'
            )
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('da.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
