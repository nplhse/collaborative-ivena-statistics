<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllocationStatsProjection>
 */
final class AllocationStatsProjectionRepository extends ServiceEntityRepository
{
    private ?bool $hasShockPregnantColumns = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllocationStatsProjection::class);
    }

    public function countCreatedInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): int
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds);

        return (int) $qb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    public function countBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.createdAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getEarliestCreatedAt(): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('p')
            ->select('MIN(p.createdAt) AS min_created_at')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * @return list<array{year:int,month:int,count:int}>
     */
    public function countByMonthInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdYear AS year', 'p.createdMonth AS month', 'COUNT(p.id) AS count')
            ->groupBy('year', 'month')
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC');

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,count:numeric-string|int}> $raw */
        $raw = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'month' => (int) $row['month'],
                'count' => (int) $row['count'],
            ],
            $raw,
        );
    }

    /**
     * @return array<string,int>
     */
    public function countByDayInPeriod(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdYear AS year', 'p.createdMonth AS month', 'p.createdDay AS day', 'COUNT(p.id) AS count')
            ->groupBy('year', 'month', 'day')
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC')
            ->addOrderBy('day', 'ASC');

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,day:numeric-string|int,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[sprintf('%04d-%02d-%02d', (int) $row['year'], (int) $row['month'], (int) $row['day'])] = (int) $row['count'];
        }

        return $out;
    }

    /**
     * @return array<string,int>
     */
    public function countByCalendarMonthInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdMonth AS month', 'COUNT(p.id) AS count')
            ->groupBy('month')
            ->orderBy('month', 'ASC');

        /** @var list<array{month:numeric-string|int,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[sprintf('cal-%02d', (int) $row['month'])] = (int) $row['count'];
        }

        return $out;
    }

    /**
     * @return array<string,int>
     */
    public function countGroupedByGenderInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.genderCode AS genderCode', 'COUNT(p.id) AS count')
            ->groupBy('genderCode');

        /** @var list<array{genderCode:int|null,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $genderCode = null !== $row['genderCode'] ? (int) $row['genderCode'] : null;
            $key = match ($genderCode) {
                AllocationStatsGenderProjectionCode::Male->value => 'M',
                AllocationStatsGenderProjectionCode::Female->value => 'F',
                AllocationStatsGenderProjectionCode::Other->value => 'X',
                default => null,
            };
            if (null !== $key) {
                $out[$key] = (int) $row['count'];
            }
        }

        return $out;
    }

    /**
     * @return array<int,int>
     */
    public function countGroupedByUrgencyInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.urgencyCode AS urgencyCode', 'COUNT(p.id) AS count')
            ->groupBy('urgencyCode');

        /** @var list<array{urgencyCode:numeric-string|int,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $urgency = (int) $row['urgencyCode'];
            if (null !== AllocationStatsUrgencyProjectionCode::tryFrom($urgency)) {
                $out[$urgency] = (int) $row['count'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByMonthAndGender(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByMonthCode('genderCode', $from, $toExclusive, $hospitalIds, static function (int $code): ?string {
            return match ($code) {
                AllocationStatsGenderProjectionCode::Male->value => 'M',
                AllocationStatsGenderProjectionCode::Female->value => 'F',
                AllocationStatsGenderProjectionCode::Other->value => 'X',
                default => null,
            };
        });
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByMonthAndUrgency(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByDayAndGender(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByDayCode('genderCode', $from, $toExclusive, $hospitalIds, static function (int $code): ?string {
            return match ($code) {
                AllocationStatsGenderProjectionCode::Male->value => 'M',
                AllocationStatsGenderProjectionCode::Female->value => 'F',
                AllocationStatsGenderProjectionCode::Other->value => 'X',
                default => null,
            };
        });
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByDayAndUrgency(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByDayCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByCalendarMonthAndGender(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByCalendarMonthCode('genderCode', $from, $toExclusive, $hospitalIds, static function (int $code): ?string {
            return match ($code) {
                AllocationStatsGenderProjectionCode::Male->value => 'M',
                AllocationStatsGenderProjectionCode::Female->value => 'F',
                AllocationStatsGenderProjectionCode::Other->value => 'X',
                default => null,
            };
        });
    }

    /**
     * @return array<string, array<string,int>>
     */
    public function bucketByCalendarMonthAndUrgency(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByCalendarMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @return array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int}
     */
    public function clinicalFeatureCounts(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $hasShockPregnant = $this->hasShockPregnantColumns();
        $shockExpr = $hasShockPregnant ? 'SUM(CASE WHEN p.isShock = true THEN 1 ELSE 0 END) AS shock' : '0 AS shock';
        $pregnantExpr = $hasShockPregnant ? 'SUM(CASE WHEN p.isPregnant = true THEN 1 ELSE 0 END) AS pregnant' : '0 AS pregnant';
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select(
                'SUM(CASE WHEN p.isWithPhysician = true THEN 1 ELSE 0 END) AS with_physician',
                'SUM(CASE WHEN p.isCpr = true THEN 1 ELSE 0 END) AS cpr',
                'SUM(CASE WHEN p.isVentilated = true THEN 1 ELSE 0 END) AS ventilated',
                $shockExpr,
                $pregnantExpr,
                'SUM(CASE WHEN p.infectionId IS NOT NULL THEN 1 ELSE 0 END) AS infectious',
            );

        /** @var array{with_physician:numeric-string|int|null,cpr:numeric-string|int|null,ventilated:numeric-string|int|null,shock:numeric-string|int|null,pregnant:numeric-string|int|null,infectious:numeric-string|int|null} $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'with_physician' => (int) ($row['with_physician'] ?? 0),
            'cpr' => (int) ($row['cpr'] ?? 0),
            'ventilated' => (int) ($row['ventilated'] ?? 0),
            'shock' => (int) ($row['shock'] ?? 0),
            'pregnant' => (int) ($row['pregnant'] ?? 0),
            'infectious' => (int) ($row['infectious'] ?? 0),
        ];
    }

    /**
     * @return array{cathlab:int,resus:int}
     */
    public function resourceFeatureCounts(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select(
                'SUM(CASE WHEN p.requiresCathlab = true THEN 1 ELSE 0 END) AS cathlab',
                'SUM(CASE WHEN p.requiresResus = true THEN 1 ELSE 0 END) AS resus',
            );

        /** @var array{cathlab:numeric-string|int|null,resus:numeric-string|int|null} $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'cathlab' => (int) ($row['cathlab'] ?? 0),
            'resus' => (int) ($row['resus'] ?? 0),
        ];
    }

    /**
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('month', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByDay(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('day', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByCalendarMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('calendar', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('month', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByDay(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('day', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByCalendarMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('calendar', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @return list<array{label:string,count:int}>
     */
    public function fetchTopDiagnosisAggregates(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, int $limit): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->leftJoin('App\Allocation\Domain\Entity\IndicationNormalized', 'inorm', 'WITH', 'inorm.id = p.indicationNormalizedId')
            ->select('COALESCE(inorm.name, :unknown) AS label', 'COUNT(p.id) AS cnt')
            ->setParameter('unknown', 'Unknown')
            ->groupBy('label')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<array{label:string,cnt:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => ['label' => (string) $row['label'], 'count' => (int) $row['cnt']],
            $rows,
        );
    }

    private function createBaseCountQb(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);

        if ($toExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere('p.createdAt < :toExclusive')
                ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }

        if (null !== $hospitalIds) {
            $qb->andWhere('p.hospitalId IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        return $qb;
    }

    /**
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    private function bucketClinicalFeatures(string $mode, \DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $hasShockPregnant = $this->hasShockPregnantColumns();
        [$bucketFields, $bucketGroupBy, $bucketKeyFromRow] = $this->bucketSpec($mode);
        $select = array_merge($bucketFields, [
            'SUM(CASE WHEN p.isWithPhysician = true THEN 1 ELSE 0 END) AS with_physician',
            'SUM(CASE WHEN p.isCpr = true THEN 1 ELSE 0 END) AS cpr',
            'SUM(CASE WHEN p.isVentilated = true THEN 1 ELSE 0 END) AS ventilated',
            $hasShockPregnant ? 'SUM(CASE WHEN p.isShock = true THEN 1 ELSE 0 END) AS shock' : '0 AS shock',
            $hasShockPregnant ? 'SUM(CASE WHEN p.isPregnant = true THEN 1 ELSE 0 END) AS pregnant' : '0 AS pregnant',
            'SUM(CASE WHEN p.infectionId IS NOT NULL THEN 1 ELSE 0 END) AS infectious',
            $hasShockPregnant
                ? 'SUM(CASE WHEN (p.isWithPhysician = true OR p.isCpr = true OR p.isVentilated = true OR p.isShock = true OR p.isPregnant = true OR p.infectionId IS NOT NULL) THEN 1 ELSE 0 END) AS with_any'
                : 'SUM(CASE WHEN (p.isWithPhysician = true OR p.isCpr = true OR p.isVentilated = true OR p.infectionId IS NOT NULL) THEN 1 ELSE 0 END) AS with_any',
        ]);
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select(...$select)
            ->groupBy(...$bucketGroupBy);

        /** @var list<array<string,mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $key = $bucketKeyFromRow($row);
            $out[$key] = [
                'with_physician' => (int) ($row['with_physician'] ?? 0),
                'cpr' => (int) ($row['cpr'] ?? 0),
                'ventilated' => (int) ($row['ventilated'] ?? 0),
                'shock' => (int) ($row['shock'] ?? 0),
                'pregnant' => (int) ($row['pregnant'] ?? 0),
                'infectious' => (int) ($row['infectious'] ?? 0),
                'with_any' => (int) ($row['with_any'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    private function bucketResources(string $mode, \DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        [$bucketFields, $bucketGroupBy, $bucketKeyFromRow] = $this->bucketSpec($mode);
        $select = array_merge($bucketFields, [
            'SUM(CASE WHEN p.requiresCathlab = true THEN 1 ELSE 0 END) AS cathlab',
            'SUM(CASE WHEN p.requiresResus = true THEN 1 ELSE 0 END) AS resus',
            'SUM(CASE WHEN (p.requiresCathlab = true OR p.requiresResus = true) THEN 1 ELSE 0 END) AS with_any',
        ]);
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select(...$select)
            ->groupBy(...$bucketGroupBy);

        /** @var list<array<string,mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $key = $bucketKeyFromRow($row);
            $out[$key] = [
                'cathlab' => (int) ($row['cathlab'] ?? 0),
                'resus' => (int) ($row['resus'] ?? 0),
                'with_any' => (int) ($row['with_any'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     0:list<string>,
     *     1:list<string>,
     *     2:\Closure(array<string,mixed>):string
     * }
     */
    private function bucketSpec(string $mode): array
    {
        return match ($mode) {
            'day' => [
                ['p.createdYear AS year', 'p.createdMonth AS month', 'p.createdDay AS day'],
                ['year', 'month', 'day'],
                static fn (array $row): string => sprintf('%04d-%02d-%02d', (int) $row['year'], (int) $row['month'], (int) $row['day']),
            ],
            'calendar' => [
                ['p.createdMonth AS month'],
                ['month'],
                static fn (array $row): string => sprintf('cal-%02d', (int) $row['month']),
            ],
            default => [
                ['p.createdYear AS year', 'p.createdMonth AS month'],
                ['year', 'month'],
                static fn (array $row): string => sprintf('%04d-%02d', (int) $row['year'], (int) $row['month']),
            ],
        };
    }

    private function hasShockPregnantColumns(): bool
    {
        if (\is_bool($this->hasShockPregnantColumns)) {
            return $this->hasShockPregnantColumns;
        }

        $columns = $this->getEntityManager()->getConnection()->createSchemaManager()->listTableColumns('allocation_stats_projection');
        $this->hasShockPregnantColumns = isset($columns['is_shock'], $columns['is_pregnant']);

        return $this->hasShockPregnantColumns;
    }

    /**
     * @param \Closure(int):?string $keyMapper
     *
     * @return array<string, array<string,int>>
     */
    private function bucketByMonthCode(string $field, \DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdYear AS year', 'p.createdMonth AS month', sprintf('p.%s AS code', $field), 'COUNT(p.id) AS count')
            ->groupBy('year', 'month', 'code');

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,code:numeric-string|int|null,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            if (null === $row['code']) {
                continue;
            }
            $key = $keyMapper((int) $row['code']);
            if (null === $key) {
                continue;
            }
            $bucket = sprintf('%04d-%02d', (int) $row['year'], (int) $row['month']);
            $out[$bucket][$key] = (int) $row['count'];
        }

        return $out;
    }

    /**
     * @param \Closure(int):?string $keyMapper
     *
     * @return array<string, array<string,int>>
     */
    private function bucketByDayCode(string $field, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdYear AS year', 'p.createdMonth AS month', 'p.createdDay AS day', sprintf('p.%s AS code', $field), 'COUNT(p.id) AS count')
            ->groupBy('year', 'month', 'day', 'code');

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,day:numeric-string|int,code:numeric-string|int|null,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            if (null === $row['code']) {
                continue;
            }
            $key = $keyMapper((int) $row['code']);
            if (null === $key) {
                continue;
            }
            $bucket = sprintf('%04d-%02d-%02d', (int) $row['year'], (int) $row['month'], (int) $row['day']);
            $out[$bucket][$key] = (int) $row['count'];
        }

        return $out;
    }

    /**
     * @param \Closure(int):?string $keyMapper
     *
     * @return array<string, array<string,int>>
     */
    private function bucketByCalendarMonthCode(string $field, \DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdMonth AS month', sprintf('p.%s AS code', $field), 'COUNT(p.id) AS count')
            ->groupBy('month', 'code');

        /** @var list<array{month:numeric-string|int,code:numeric-string|int|null,count:numeric-string|int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            if (null === $row['code']) {
                continue;
            }
            $key = $keyMapper((int) $row['code']);
            if (null === $key) {
                continue;
            }
            $bucket = sprintf('cal-%02d', (int) $row['month']);
            $out[$bucket][$key] = (int) $row['count'];
        }

        return $out;
    }
}
