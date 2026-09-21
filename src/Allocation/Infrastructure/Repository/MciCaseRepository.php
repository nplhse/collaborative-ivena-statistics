<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Application\Filter\OptionalRelationFilter;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MciCase>
 */
final class MciCaseRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MciCase::class);
    }

    public function deleteByImport(Import $import): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('IDENTITY(m.import) = :importId')
            ->setParameter('importId', $import->getId(), Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    public function getListPaginator(MciCaseQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id, m.publicId, m.createdAt, m.arrivalAt,
                h.id as hospital_id, h.publicId as hospital_public_id, h.name as hospital,
                da.id as dispatch_area_id, da.name as dispatchArea,
                s.id as state_id, s.name as state,
                m.mciId, m.mciTitle,
                m.gender, m.age,
                m.requiresResus, m.requiresCathlab,
                m.isCPR, m.isVentilated, m.isShock, m.isPregnant, m.isWithPhysician,
                m.transportType, m.urgency,
                i.name as infection,
                iraw.name as indicationRawName, iraw.code as indicationRawCode,
                inor.name as indicationNormalizedName, inor.code as indicationNormalizedCode')
            ->leftJoin(
                State::class,
                's',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.state = s.id'
            )
            ->leftJoin(
                DispatchArea::class,
                'da',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.dispatchArea = da.id'
            )
            ->leftJoin(
                Hospital::class,
                'h',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.hospital = h.id'
            )
            ->leftJoin(
                Infection::class,
                'i',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.infection = i.id'
            )
            ->leftJoin(
                IndicationRaw::class,
                'iraw',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.indicationRaw = iraw.id'
            )
            ->leftJoin(
                IndicationNormalized::class,
                'inor',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'm.indicationNormalized = inor.id'
            );

        if (null !== $queryParametersDTO->importId) {
            $qb->andWhere('m.import = :importId')
                ->setParameter('importId', $queryParametersDTO->importId);
        }

        $mciId = $queryParametersDTO->normalizedMciId();
        if (null !== $mciId) {
            $qb->andWhere('m.mciId = :mciId')
                ->setParameter('mciId', $mciId);
        }

        $search = $queryParametersDTO->normalizedSearch();
        if (null !== $search) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('LOWER(m.mciId)', ':search'),
                $qb->expr()->like('LOWER(m.mciTitle)', ':search'),
            ))->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $this->applyListFilters($qb, $queryParametersDTO);

        $field = match ($queryParametersDTO->sortBy) {
            'arrivalAt' => 'm.arrivalAt',
            'mciTitle' => 'm.mciTitle',
            'createdAt' => 'm.createdAt',
            default => 'm.createdAt',
        };

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    private function applyListFilters(QueryBuilder $qb, MciCaseQueryParametersDTO $query): void
    {
        if (null !== $query->hospital) {
            $qb->andWhere('h.id = :hospitalId')
                ->setParameter('hospitalId', $query->hospital);
        }

        if (null !== $query->state) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $query->state);
        }

        if (null !== $query->dispatchArea) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $query->dispatchArea);
        }

        $arrival = $query->arrivalAtRange();
        if ($arrival->from instanceof \DateTimeImmutable) {
            $qb->andWhere('m.arrivalAt >= :arrivalFrom')
                ->setParameter('arrivalFrom', $arrival->from, Types::DATETIME_IMMUTABLE);
        }
        if ($arrival->toExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere('m.arrivalAt < :arrivalUntil')
                ->setParameter('arrivalUntil', $arrival->toExclusive, Types::DATETIME_IMMUTABLE);
        }

        $urgency = AllocationUrgency::tryFromQueryValue($query->urgency);
        if ($urgency instanceof AllocationUrgency) {
            $qb->andWhere('m.urgency = :urgency')
                ->setParameter('urgency', $urgency->value, Types::INTEGER);
        }

        if (null !== $query->transportType && '' !== $query->transportType) {
            $transportType = AllocationTransportType::tryFrom($query->transportType);
            if ($transportType instanceof AllocationTransportType) {
                $qb->andWhere('m.transportType = :transportType')
                    ->setParameter('transportType', $transportType->value, Types::STRING);
            }
        }

        if (null !== $query->department) {
            $qb->andWhere('IDENTITY(m.department) = :departmentId')
                ->setParameter('departmentId', $query->department);
        }

        if (null !== $query->speciality) {
            $qb->andWhere('IDENTITY(m.speciality) = :specialityId')
                ->setParameter('specialityId', $query->speciality);
        }

        if (null !== $query->departmentWasClosed) {
            $qb->andWhere('m.departmentWasClosed = :departmentWasClosed')
                ->setParameter('departmentWasClosed', filter_var($query->departmentWasClosed, FILTER_VALIDATE_BOOLEAN));
        }

        foreach ([
            'requiresResus' => 'm.requiresResus',
            'requiresCathlab' => 'm.requiresCathlab',
            'isVentilated' => 'm.isVentilated',
            'isShock' => 'm.isShock',
            'isCPR' => 'm.isCPR',
            'isPregnant' => 'm.isPregnant',
            'isWithPhysician' => 'm.isWithPhysician',
        ] as $param => $field) {
            $value = $query->{$param};
            if (null === $value) {
                continue;
            }

            $qb->andWhere($field.' = :'.$param)
                ->setParameter($param, filter_var($value, FILTER_VALIDATE_BOOLEAN));
        }

        if (null !== $query->indication) {
            $qb->andWhere('inor.code = :indicationCode')
                ->setParameter('indicationCode', $query->indication);
        }

        OptionalRelationFilter::fromQuery($query->occasion, allowPresent: false)->apply(
            $qb,
            'm.occasion IS NULL',
            'm.occasion IS NOT NULL',
            'IDENTITY(m.occasion)',
            'occasionId',
        );

        OptionalRelationFilter::fromQuery($query->infection)->apply(
            $qb,
            'm.infection IS NULL',
            'm.infection IS NOT NULL',
            'i.id',
            'infectionId',
        );
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('m')
            ->where('m.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('m.createdAt', 'ASC');

        /** @var MciCase[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];

        foreach ($rows as $mciCase) {
            $createdAt = $mciCase->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $key = $createdAt->format('Y-m');

            $buckets[$key] ??= 0;

            ++$buckets[$key];
        }

        $result = [];
        foreach ($buckets as $key => $count) {
            [$year, $month] = explode('-', $key);

            $result[] = [
                'year' => (int) $year,
                'month' => (int) $month,
                'count' => $count,
            ];
        }

        usort($result, static fn (array $a, array $b): int => [$a['year'], $a['month']] <=> [$b['year'], $b['month']]);

        return $result;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBefore(\DateTimeInterface $before): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
