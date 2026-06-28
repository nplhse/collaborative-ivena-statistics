<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Application\Export\AllocationListFilterApplicator;
use App\Allocation\Application\Export\DTO\ExportDateTimeRange;
use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Application\Export\ExportDateTimeRangeResolver;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class OwnHospitalAllocationsExportQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AllocationListFilterApplicator $filterApplicator,
        private ExportDateTimeRangeResolver $dateTimeRangeResolver,
    ) {
    }

    /**
     * @param list<int> $exportHospitalIds
     */
    public function count(array $exportHospitalIds, OwnHospitalAllocationsExportFilter $filter): int
    {
        if ([] === $exportHospitalIds) {
            return 0;
        }

        $qb = $this->createBaseQueryBuilder($exportHospitalIds);
        $this->applyFilter($qb, $filter);

        return (int) $qb
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $exportHospitalIds
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterateRows(array $exportHospitalIds, OwnHospitalAllocationsExportFilter $filter): iterable
    {
        if ([] === $exportHospitalIds) {
            return;
        }

        $qb = $this->createBaseQueryBuilder($exportHospitalIds);
        $this->applyFilter($qb, $filter);
        $qb->select($this->exportSelectFields())
            ->orderBy('a.arrivalAt', 'ASC')
            ->addOrderBy('a.id', 'ASC');

        /** @var iterable<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->toIterable();

        yield from $rows;
    }

    /**
     * @param list<int> $exportHospitalIds
     */
    private function createBaseQueryBuilder(array $exportHospitalIds): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Allocation::class, 'a')
            ->leftJoin(State::class, 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.state = s.id')
            ->leftJoin(DispatchArea::class, 'da', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.dispatchArea = da.id')
            ->leftJoin(Hospital::class, 'h', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.hospital = h.id')
            ->leftJoin(Infection::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.infection = i.id')
            ->leftJoin(SecondaryTransport::class, 'st', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.secondaryTransport = st.id')
            ->leftJoin(IndicationNormalized::class, 'inor', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.indicationNormalized = inor.id')
            ->leftJoin(IndicationRaw::class, 'iraw', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.indicationRaw = iraw.id')
            ->leftJoin(Department::class, 'dep', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.department = dep.id')
            ->leftJoin(Speciality::class, 'spec', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.speciality = spec.id')
            ->leftJoin(Assignment::class, 'asgn', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.assignment = asgn.id')
            ->leftJoin(Occasion::class, 'occ', \Doctrine\ORM\Query\Expr\Join::WITH, 'a.occasion = occ.id')
            ->andWhere('h.id IN (:exportHospitalIds)')
            ->setParameter('exportHospitalIds', $exportHospitalIds);

        return $qb;
    }

    private function applyFilter(QueryBuilder $qb, OwnHospitalAllocationsExportFilter $filter): void
    {
        $range = $this->dateTimeRangeResolver->resolve(
            $filter->dateFrom,
            $filter->dateTo,
            $filter->timeFrom,
            $filter->timeTo,
        );

        $this->filterApplicator->applyArrivalAtRange($qb, $range);
        $this->filterApplicator->apply($qb, $filter->toListFilterCriteria());
    }

    /**
     * @return list<string>
     */
    private function exportSelectFields(): array
    {
        return [
            'a.arrivalAt',
            'a.createdAt',
            'h.name as hospital',
            's.name as state',
            'da.name as dispatchArea',
            'a.gender',
            'a.age',
            'a.urgency',
            'a.transportType',
            'inor.name as indicationNormalized',
            'iraw.name as indicationRaw',
            'st.name as secondaryTransport',
            'dep.name as department',
            'spec.name as speciality',
            'a.departmentWasClosed',
            'asgn.name as assignment',
            'occ.name as occasion',
            'a.requiresResus',
            'a.requiresCathlab',
            'a.isCPR',
            'a.isVentilated',
            'a.isShock',
            'a.isPregnant',
            'a.isWorkAccident',
            'a.isWithPhysician',
            'i.name as infection',
        ];
    }

    public function resolveDateTimeRange(OwnHospitalAllocationsExportFilter $filter): ExportDateTimeRange
    {
        return $this->dateTimeRangeResolver->resolve(
            $filter->dateFrom,
            $filter->dateTo,
            $filter->timeFrom,
            $filter->timeTo,
        );
    }
}
