<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProjectionTimeSeriesQuery
{
    use ProjectionCountQueryTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
        private ProjectionDrawerFilterApplier $drawerFilterApplier,
    ) {
    }

    #[\Override]
    protected function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    #[\Override]
    protected function filterApplier(): ProjectionFilterApplier
    {
        return $this->filterApplier;
    }

    #[\Override]
    protected function drawerFilterApplier(): ProjectionDrawerFilterApplier
    {
        return $this->drawerFilterApplier;
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public function countCreatedInPeriod(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): int {
        return (int) $this->createBaseCountQb($from, $toExclusive, $hospitalIds, $drawerFilter)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBefore(\DateTimeImmutable $before): int
    {
        static $cache = [];
        $cacheKey = $before->format(\DateTimeInterface::ATOM);
        if (\array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('COUNT(p.id)')
            ->where('p.createdAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        $cache[$cacheKey] = $count;

        return $count;
    }

    public function getEarliestCreatedAt(): ?\DateTimeImmutable
    {
        static $cached = null;
        static $loaded = false;
        if ($loaded) {
            return $cached;
        }

        $value = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('MIN(p.createdAt) AS min_created_at')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $value || '' === $value) {
            $loaded = true;

            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $cached = \DateTimeImmutable::createFromInterface($value);
            $loaded = true;

            return $cached;
        }

        $cached = new \DateTimeImmutable((string) $value);
        $loaded = true;

        return $cached;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{year:int,month:int,count:int}>
     */
    public function countByMonthInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
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
     * @return list<array{year:int,count:int}>
     */
    public function countByYearInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.createdYear AS year', 'COUNT(p.id) AS count')
            ->groupBy('year')
            ->orderBy('year', 'ASC');

        /** @var list<array{year:numeric-string|int,count:numeric-string|int}> $raw */
        $raw = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'count' => (int) $row['count'],
            ],
            $raw,
        );
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{year:int,count:int}>
     */
    public function countGroupedByCreatedYear(int $startYear, ?int $endYearExclusive, ?array $hospitalIds): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->andWhere('p.createdYear >= :startYear')
            ->setParameter('startYear', $startYear)
            ->select('p.createdYear AS year', 'COUNT(p.id) AS count')
            ->groupBy('year')
            ->orderBy('year', 'ASC');

        if (null !== $endYearExclusive) {
            $qb->andWhere('p.createdYear < :endYearExclusive')
                ->setParameter('endYearExclusive', $endYearExclusive);
        }

        $this->filterApplier->applyHospitalScope($qb, 'p.hospitalId', $hospitalIds);

        /** @var list<array{year:numeric-string|int,count:numeric-string|int}> $raw */
        $raw = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'count' => (int) $row['count'],
            ],
            $raw,
        );
    }

    public function countWithCreatedYearBefore(int $beforeYear): int
    {
        static $cache = [];
        if (\array_key_exists($beforeYear, $cache)) {
            return $cache[$beforeYear];
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('COUNT(p.id)')
            ->where('p.createdYear < :beforeYear')
            ->setParameter('beforeYear', $beforeYear)
            ->getQuery()
            ->getSingleScalarResult();

        $cache[$beforeYear] = $count;

        return $count;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,int>
     */
    public function countByDayInPeriod(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
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
    public function countByCalendarMonthInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
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
    public function countGroupedByGenderInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        $qb = $this->createBaseCountQb($from, $toExclusive, $hospitalIds)
            ->select('p.genderCode AS genderCode', 'COUNT(p.id) AS count')
            ->groupBy('genderCode');

        /** @var list<array{genderCode:numeric-string|int|null,count:numeric-string|int}> $rows */
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
    public function countGroupedByUrgencyInPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
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
}
