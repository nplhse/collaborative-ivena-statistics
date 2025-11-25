<?php

namespace App\Query;

use App\DataTransferObjects\AllocationQueryParametersDTO;
use App\Entity\Allocation;
use App\Entity\DispatchArea;
use App\Entity\Hospital;
use App\Entity\IndicationNormalized;
use App\Entity\IndicationRaw;
use App\Entity\Infection;
use App\Entity\State;
use App\Enum\AllocationUrgency;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Shared\Infrastructure\Pagination\Paginator;
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
                h.id as hospital_id, h.name as hospital, h.tier, h.size, h.location, a.gender, a.age, a.requiresResus, a.requiresCathlab, a.isCPR, a.isVentilated, a.isShock,
                a.isPregnant, a.isWithPhysician, a.urgency, i.name as infection, iraw.name as indicationRawName, iraw.code indicationRawCode,
                inor.name as indicationNormalizedName, inor.code as indicationNormalizedCode')
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
            )
            ->leftJoin(
                Infection::class,
                'i',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.infection = i.id'
            )
            ->leftJoin(
                IndicationRaw::class,
                'iraw',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.indicationRaw = iraw.id'
            )
            ->leftJoin(
                IndicationNormalized::class,
                'inor',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.indicationNormalized = inor.id'
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

        if (null !== $queryParametersDTO->tier && '' !== $queryParametersDTO->tier) {
            $qb->andWhere('h.tier = :tier')
                ->setParameter('tier', HospitalTier::from($queryParametersDTO->tier));
        }

        if (null !== $queryParametersDTO->location && '' !== $queryParametersDTO->location) {
            $qb->andWhere('h.location = :location')
                ->setParameter('location', HospitalLocation::from($queryParametersDTO->location));
        }

        if (null !== $queryParametersDTO->size && '' !== $queryParametersDTO->size) {
            $qb->andWhere('h.size = :size')
                ->setParameter('size', HospitalSize::from($queryParametersDTO->size));
        }

        if (null !== $queryParametersDTO->urgency && '' !== $queryParametersDTO->urgency) {
            $qb->andWhere('a.urgency = :urgency')
                ->setParameter('urgency', AllocationUrgency::from($queryParametersDTO->urgency));
        }

        if (null !== $queryParametersDTO->state) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $queryParametersDTO->state);
        }

        if (null !== $queryParametersDTO->dispatchArea) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $queryParametersDTO->dispatchArea);
        }

        if (null !== $queryParametersDTO->requiresResus) {
            $qb->andWhere('a.requiresResus = :requiresResus')
                ->setParameter(
                    'requiresResus',
                    filter_var($queryParametersDTO->requiresResus, FILTER_VALIDATE_BOOLEAN)
                );
        }

        if (null !== $queryParametersDTO->requiresCathlab) {
            $qb->andWhere('a.requiresCathlab = :requiresCathlab')
                ->setParameter(
                    'requiresCathlab',
                    filter_var($queryParametersDTO->requiresCathlab, FILTER_VALIDATE_BOOLEAN)
                );
        }

        if (null !== $queryParametersDTO->indication) {
            $qb->andWhere('inor.code = :indication')
                ->setParameter('indication', $queryParametersDTO->indication);
        }

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        return (new Paginator($qb))->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }
}
