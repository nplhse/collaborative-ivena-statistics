<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProjectionTimeSeriesBucketQuery
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
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByMonthAndGender(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByMonthCode('genderCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => match ($code) {
            AllocationStatsGenderProjectionCode::Male->value => 'M',
            AllocationStatsGenderProjectionCode::Female->value => 'F',
            AllocationStatsGenderProjectionCode::Other->value => 'X',
            default => null,
        });
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByMonthAndUrgency(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): string => (string) $code);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByDayAndGender(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByDayCode('genderCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => match ($code) {
            AllocationStatsGenderProjectionCode::Male->value => 'M',
            AllocationStatsGenderProjectionCode::Female->value => 'F',
            AllocationStatsGenderProjectionCode::Other->value => 'X',
            default => null,
        });
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByDayAndUrgency(?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByDayCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): string => (string) $code);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByCalendarMonthAndGender(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByCalendarMonthCode('genderCode', $from, $toExclusive, $hospitalIds, static fn (int $code): ?string => match ($code) {
            AllocationStatsGenderProjectionCode::Male->value => 'M',
            AllocationStatsGenderProjectionCode::Female->value => 'F',
            AllocationStatsGenderProjectionCode::Other->value => 'X',
            default => null,
        });
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    public function bucketByCalendarMonthAndUrgency(?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketByCalendarMonthCode('urgencyCode', $from, $toExclusive, $hospitalIds, static fn (int $code): string => (string) $code);
    }

    /**
     * @param \Closure(int):?string $keyMapper
     * @param list<int>|null        $hospitalIds
     *
     * @return array<string,array<string,int>>
     */
    private function bucketByMonthCode(string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
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
    private function bucketByDayCode(string $field, ?\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
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
    private function bucketByCalendarMonthCode(string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds, \Closure $keyMapper): array
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
