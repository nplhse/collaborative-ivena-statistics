<?php

namespace App\Query;

use App\DataTransferObjects\AllocationQueryParametersDTO;
use App\Entity\Allocation;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\State;
use App\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;

final class ListAllocationsQuery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getPaginator(AllocationQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a.id, a.createdAt, a.arrivalAt, s.id as state_id, s.name as state, da.id as dispatchArea_id, da.name as dispatchArea,
                h.id as hospital_id, h.name as hospital, a.gender, a.age, a.requiresResus, a.requiresCathlab, a.isCPR, a.isVentilated, a.isShock, a.isPregnant, a.isWithPhysician, a.urgency')
            ->from(Allocation::class, 'a')
            ->leftJoin(
                State::class,
                's',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.state = s.id'
            )
            ->leftJoin(
                DispatchArea::class,
                'da',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.dispatchArea = da.id'
            )
            ->leftJoin(
                Hospital::class,
                'h',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.hospital = h.id'
            );

        if (null !== $queryParametersDTO->importId) {
            $qb->andWhere('a.import = :importId')
            ->setParameter('importId', $queryParametersDTO->importId);
        }

        $field = match ($queryParametersDTO->sortBy) {
            'arrivalAt' => 'a.arrivalAt',
            'age' => 'a.age',
            default => 'h.'.$queryParametersDTO->sortBy,
        };

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        return (new Paginator($qb))->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
