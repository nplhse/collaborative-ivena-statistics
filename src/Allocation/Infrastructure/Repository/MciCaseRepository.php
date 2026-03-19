<?php

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MciCase>
 */
final class MciCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MciCase::class);
    }

    public function getListPaginator(MciCaseQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id, m.createdAt, m.arrivalAt,
                h.id as hospital_id, h.name as hospital,
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

        $field = match ($queryParametersDTO->sortBy) {
            'arrivalAt' => 'm.arrivalAt',
            'mciTitle' => 'm.mciTitle',
            default => 'm.createdAt',
        };

        $qb->orderBy($field, $queryParametersDTO->orderBy);

        return (new Paginator($qb))->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = (new \DateTimeImmutable('first day of this month'))
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('m')
            ->where('m.createdAt >= :from')
            ->setParameter('from', $from)
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

            if (!isset($buckets[$key])) {
                $buckets[$key] = 0;
            }

            ++$buckets[$key];
        }

        $result = [];
        foreach ($buckets as $key => $count) {
            [$year, $month] = explode('-', $key);

            $result[] = [
                'year' => (int) $year,
                'month' => (int) $month,
                'count' => (int) $count,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return [$a['year'], $a['month']] <=> [$b['year'], $b['month']];
        });

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
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
