<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AllocationTimeSeriesQuery
{
    use HospitalScopeQueryTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateCountByMonthSince($from, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateCountByMonthSince($from, $hospitalIds);
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countAllocationsByMonthInRange(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        return $this->aggregateCountByMonthInRange($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countAllocationsByMonthInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateCountByMonthInRange($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, int>
     */
    public function countAllocationsByCalendarMonthOfYearInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateCountByCalendarMonthOfYear($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int>
     */
    public function countAllocationsByCalendarMonthOfYearInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateCountByCalendarMonthOfYear($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, int>
     */
    public function countAllocationsByDayInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateCountByDay($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int>
     */
    public function countAllocationsByDayInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateCountByDay($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    private function aggregateCountByMonthSince(\DateTimeImmutable $from, ?array $hospitalIds): array
    {
        $sql = <<<'SQL'
SELECT EXTRACT(YEAR FROM created_at)::INT AS year,
       EXTRACT(MONTH FROM created_at)::INT AS month,
       COUNT(id)::INT AS count
FROM allocation
WHERE created_at >= :from
  AND created_at IS NOT NULL
SQL;
        $params = ['from' => $from];
        $types = ['from' => Types::DATETIME_IMMUTABLE];

        if (null !== $hospitalIds) {
            $sql .= ' AND hospital_id IN (:hospitalIds)';
            $params['hospitalIds'] = $hospitalIds;
            $types['hospitalIds'] = ArrayParameterType::INTEGER;
        }

        $sql .= ' GROUP BY 1, 2 ORDER BY 1 ASC, 2 ASC';

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,count:numeric-string|int}> $raw */
        $raw = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params, $types);

        return $this->mapMonthCountRows($raw);
    }

    /**
     * @param list<array{year:numeric-string|int,month:numeric-string|int,count:numeric-string|int}> $raw
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    private function mapMonthCountRows(array $raw): array
    {
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
     * @return array<int, array{year: int, month: int, count: int}>
     */
    private function aggregateCountByMonthInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $this->applyHospitalScope($qb, $hospitalIds);

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
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
                'count' => $count,
            ];
        }

        usort($result, static fn (array $a, array $b): int => [$a['year'], $a['month']] <=> [$b['year'], $b['month']]);

        return $result;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, int>
     */
    private function aggregateCountByCalendarMonthOfYear(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $this->applyHospitalScope($qb, $hospitalIds);

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach (range(1, 12) as $m) {
            $counts[sprintf('cal-%02d', $m)] = 0;
        }

        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $k = sprintf('cal-%02d', (int) $createdAt->format('n'));
            ++$counts[$k];
        }

        return $counts;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, int>
     */
    private function aggregateCountByDay(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $this->applyHospitalScope($qb, $hospitalIds);

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $k = $createdAt->format('Y-m-d');
            if (!isset($counts[$k])) {
                $counts[$k] = 0;
            }
            ++$counts[$k];
        }

        return $counts;
    }
}
