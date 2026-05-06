<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class ProjectionFeatureQuery
{
    private ?bool $hasShockPregnantColumns = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectionFilterApplier $filterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('month', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByDay(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('day', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int,with_any:int}>
     */
    public function bucketClinicalFeaturesByCalendarMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketClinicalFeatures('calendar', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('month', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByDay(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('day', $from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string,array{cathlab:int,resus:int,with_any:int}>
     */
    public function bucketResourcesByCalendarMonth(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, ?array $hospitalIds): array
    {
        return $this->bucketResources('calendar', $from, $toExclusive, $hospitalIds);
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
     * @param list<int>|null $hospitalIds
     *
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
     * @param list<int>|null $hospitalIds
     *
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

        $columns = $this->entityManager->getConnection()->createSchemaManager()->listTableColumns('allocation_stats_projection');
        $this->hasShockPregnantColumns = isset($columns['is_shock'], $columns['is_pregnant']);

        return $this->hasShockPregnantColumns;
    }
}
