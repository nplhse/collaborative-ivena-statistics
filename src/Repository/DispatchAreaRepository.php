<?php

namespace App\Repository;

use App\DataTransferObjects\AreaListQueryParametersDTO;
use App\Entity\DispatchArea;
use App\Entity\State;
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

    /**
     * @return DispatchArea[] Returns an array of DispatchArea objects
     */
    public function findAreasByQueryParameterDTO(AreaListQueryParametersDTO $queryParametersDTO): array
    {
        return $this->createQueryBuilder('da')
            ->leftJoin(
                State::class,
                's',
                Join::WITH,
                'da.state = s.id'
            )
            ->setFirstResult(($queryParametersDTO->page - 1) * $queryParametersDTO->limit)
            ->orderBy('da.id', $queryParametersDTO->orderBy)
            ->setMaxResults($queryParametersDTO->limit)
            ->getQuery()
            ->getResult()
        ;
    }
}
