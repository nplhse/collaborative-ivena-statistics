<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Export\DTO\AllocationListFilterCriteria;
use App\Allocation\Application\Export\DTO\ExportDateTimeRange;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use Doctrine\ORM\QueryBuilder;

final class AllocationListFilterApplicator
{
    public function apply(QueryBuilder $qb, AllocationListFilterCriteria $criteria): void
    {
        if (null !== $criteria->importId) {
            $qb->andWhere('a.import = :importId')
                ->setParameter('importId', $criteria->importId);
        }

        if (null !== $criteria->tier && '' !== $criteria->tier) {
            $qb->andWhere('h.tier = :tier')
                ->setParameter('tier', HospitalTier::from($criteria->tier));
        }

        if (null !== $criteria->location && '' !== $criteria->location) {
            $qb->andWhere('h.location = :location')
                ->setParameter('location', HospitalLocation::from($criteria->location));
        }

        if (null !== $criteria->size && '' !== $criteria->size) {
            $qb->andWhere('h.size = :size')
                ->setParameter('size', HospitalSize::from($criteria->size));
        }

        if (null !== $criteria->urgency && '' !== $criteria->urgency) {
            $urgency = AllocationUrgency::tryFromQueryValue($criteria->urgency);
            if ($urgency instanceof AllocationUrgency) {
                $qb->andWhere('a.urgency = :urgency')
                    ->setParameter('urgency', $urgency->value);
            }
        }

        if (null !== $criteria->state) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $criteria->state);
        }

        if (null !== $criteria->dispatchArea) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $criteria->dispatchArea);
        }

        if (null !== $criteria->requiresResus) {
            $qb->andWhere('a.requiresResus = :requiresResus')
                ->setParameter(
                    'requiresResus',
                    filter_var($criteria->requiresResus, FILTER_VALIDATE_BOOLEAN)
                );
        }

        if (null !== $criteria->requiresCathlab) {
            $qb->andWhere('a.requiresCathlab = :requiresCathlab')
                ->setParameter(
                    'requiresCathlab',
                    filter_var($criteria->requiresCathlab, FILTER_VALIDATE_BOOLEAN)
                );
        }

        foreach ([
            'isVentilated' => 'a.isVentilated',
            'isShock' => 'a.isShock',
            'isCPR' => 'a.isCPR',
            'isPregnant' => 'a.isPregnant',
            'isWorkAccident' => 'a.isWorkAccident',
        ] as $param => $booleanField) {
            if (null !== $criteria->{$param}) {
                $qb->andWhere($booleanField.' = :'.$param)
                    ->setParameter(
                        $param,
                        filter_var($criteria->{$param}, FILTER_VALIDATE_BOOLEAN)
                    );
            }
        }

        if (null !== $criteria->isInfectious) {
            $isInfectious = filter_var($criteria->isInfectious, FILTER_VALIDATE_BOOLEAN);
            $qb->andWhere($isInfectious ? 'a.infection IS NOT NULL' : 'a.infection IS NULL');
        }

        if (null !== $criteria->infection) {
            $qb->andWhere('i.id = :infectionId')
                ->setParameter('infectionId', $criteria->infection);
        }

        if (null !== $criteria->indication) {
            $qb->andWhere('inor.code = :indication')
                ->setParameter('indication', $criteria->indication);
        }

        if (null !== $criteria->secondaryTransport) {
            $qb->andWhere('st.id = :secondaryTransportId')
                ->setParameter('secondaryTransportId', $criteria->secondaryTransport);
        }

        if (null !== $criteria->department) {
            $qb->andWhere('IDENTITY(a.department) = :departmentId')
                ->setParameter('departmentId', $criteria->department);
        }

        if (null !== $criteria->speciality) {
            $qb->andWhere('IDENTITY(a.speciality) = :specialityId')
                ->setParameter('specialityId', $criteria->speciality);
        }

        if (null !== $criteria->assignment) {
            $qb->andWhere('IDENTITY(a.assignment) = :assignmentId')
                ->setParameter('assignmentId', $criteria->assignment);
        }

        if (null !== $criteria->occasion) {
            $qb->andWhere('IDENTITY(a.occasion) = :occasionId')
                ->setParameter('occasionId', $criteria->occasion);
        }

        if (null !== $criteria->departmentWasClosed) {
            $qb->andWhere('a.departmentWasClosed = :departmentWasClosed')
                ->setParameter(
                    'departmentWasClosed',
                    filter_var($criteria->departmentWasClosed, FILTER_VALIDATE_BOOLEAN),
                );
        }

        if (null !== $criteria->transportType && '' !== $criteria->transportType) {
            $qb->andWhere('a.transportType = :transportType')
                ->setParameter('transportType', AllocationTransportType::from($criteria->transportType));
        }
    }

    public function applyArrivalAtRange(QueryBuilder $qb, ExportDateTimeRange $range): void
    {
        $qb->andWhere('a.arrivalAt >= :exportFrom')
            ->andWhere('a.arrivalAt <= :exportTo')
            ->setParameter('exportFrom', $range->from)
            ->setParameter('exportTo', $range->to);
    }
}
