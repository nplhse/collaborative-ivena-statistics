<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ProjectionTimeSeriesQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public function countCreatedInPeriod(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): int
    {
        return (int) $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('COUNT(p.id)')
            ->where('p.createdAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getEarliestCreatedAt(): ?\DateTimeImmutable
    {
        $value = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByMonthAndUrgency(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByDayAndUrgency(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByDayCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByCalendarMonthAndUrgency(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByCalendarMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => (string) $code);
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    private function createBaseCountQb(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p');
        $this->filterApplier->applyCreatedAtRange($qb, 'p.createdAt', $from, $toExclusive);
        $this->filterApplier->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);

        return $qb;
    }

    /**
     * @param \Closure(int):?string $keyMapper
     * @param list<int>|null        $hospitalIds
     *
     * @return array<string,array<string,int>>
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
     * @param list<int>|null        $hospitalIds
     *
     * @return array<string,array<string,int>>
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
     * @param list<int>|null        $hospitalIds
     *
     * @return array<string,array<string,int>>
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
