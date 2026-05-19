<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\Application\Pivot\HospitalPivotDimension;
use App\Statistics\Application\Pivot\HospitalPivotMeasure;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HospitalPivotQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{row_key: string, col_key: string, value: float}>
     */
    public function fetchCells(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        HospitalPivotDimension $rows,
        HospitalPivotDimension $cols,
        HospitalPivotMeasure $measure,
    ): array {
        if (\is_array($hospitalIds) && [] === $hospitalIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->from(Hospital::class, 'h');

        $this->filterApplier->applyHospitalScope($qb, 'h.id', $hospitalIds);

        $qb->leftJoin('h.state', 's')
            ->leftJoin('h.dispatchArea', 'da');

        $allocationJoinConditions = ['a.hospitalId = h.id'];
        if ($from instanceof \DateTimeImmutable) {
            $allocationJoinConditions[] = 'a.createdAt >= :from';
            $qb->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        }
        if ($toExclusive instanceof \DateTimeImmutable) {
            $allocationJoinConditions[] = 'a.createdAt < :toExclusive';
            $qb->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }
        $qb->leftJoin(
            \App\Statistics\Infrastructure\Entity\AllocationStatsProjection::class,
            'a',
            'WITH',
            implode(' AND ', $allocationJoinConditions),
        );

        $rowExpr = $this->dimensionExpression($rows);
        $colExpr = $this->dimensionExpression($cols);

        $qb->select(sprintf('%s AS row_key', $rowExpr))
            ->addSelect(sprintf('%s AS col_key', $colExpr))
            ->addSelect('h.id AS hospital_id')
            ->addSelect('h.beds AS beds')
            ->addSelect('COUNT(a.id) AS allocation_count')
            ->groupBy('row_key', 'col_key', 'hospital_id', 'beds');

        /** @var list<array<string, mixed>> $raw */
        $raw = $qb->getQuery()->getArrayResult();
        if ([] === $raw) {
            $raw = $this->fetchLegacyAllocationRows($from, $toExclusive, $hospitalIds, $rows, $cols);
        }

        return $this->aggregateByCell($raw, $measure);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array<string,mixed>>
     */
    private function fetchLegacyAllocationRows(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        HospitalPivotDimension $rows,
        HospitalPivotDimension $cols,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Hospital::class, 'h')
            ->leftJoin('h.state', 's')
            ->leftJoin('h.dispatchArea', 'da');

        $legacyJoinConditions = ['a.hospital = h'];
        if ($from instanceof \DateTimeImmutable) {
            $legacyJoinConditions[] = 'a.createdAt >= :from';
            $qb->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        }
        if ($toExclusive instanceof \DateTimeImmutable) {
            $legacyJoinConditions[] = 'a.createdAt < :toExclusive';
            $qb->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }
        $qb->leftJoin(\App\Allocation\Domain\Entity\Allocation::class, 'a', 'WITH', implode(' AND ', $legacyJoinConditions));

        $this->filterApplier->applyHospitalScope($qb, 'h.id', $hospitalIds);
        if ($toExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere('a.createdAt < :toExclusive OR a.id IS NULL')
                ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }

        $rowExpr = $this->dimensionExpression($rows);
        $colExpr = $this->dimensionExpression($cols);
        $qb->select(sprintf('%s AS row_key', $rowExpr))
            ->addSelect(sprintf('%s AS col_key', $colExpr))
            ->addSelect('h.id AS hospital_id')
            ->addSelect('h.beds AS beds')
            ->addSelect('COUNT(a.id) AS allocation_count')
            ->groupBy('row_key', 'col_key', 'hospital_id', 'beds');

        /** @var list<array<string,mixed>> $raw */
        $raw = $qb->getQuery()->getArrayResult();

        return $raw;
    }

    private function dimensionExpression(HospitalPivotDimension $dimension): string
    {
        return match ($dimension) {
            HospitalPivotDimension::State => 's.name',
            HospitalPivotDimension::DispatchArea => 'da.name',
            HospitalPivotDimension::Location => 'h.location',
            HospitalPivotDimension::Tier => 'h.tier',
            HospitalPivotDimension::Size => 'h.size',
        };
    }

    private function scalarKey(mixed $value): string
    {
        if ($value instanceof \UnitEnum && property_exists($value, 'value')) {
            $enumValue = $value->value;

            return (string) $enumValue;
        }

        return (string) $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{row_key: string, col_key: string, value: float}>
     */
    private function aggregateByCell(array $rows, HospitalPivotMeasure $measure): array
    {
        /** @var array<string, array{row_key: string, col_key: string, hospital_count: int, beds_sum: int, beds_count: int, beds_min: ?int, beds_max: ?int, allocations_sum: int, allocations_min: ?int, allocations_max: ?int}> $agg */
        $agg = [];

        foreach ($rows as $row) {
            $rk = $this->scalarKey($row['row_key'] ?? '');
            $ck = $this->scalarKey($row['col_key'] ?? '');
            $key = $rk.'|'.$ck;

            $beds = isset($row['beds']) ? (int) $row['beds'] : null;
            $alloc = isset($row['allocation_count']) ? (int) $row['allocation_count'] : 0;

            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'row_key' => $rk,
                    'col_key' => $ck,
                    'hospital_count' => 0,
                    'beds_sum' => 0,
                    'beds_count' => 0,
                    'beds_min' => null,
                    'beds_max' => null,
                    'allocations_sum' => 0,
                    'allocations_min' => null,
                    'allocations_max' => null,
                ];
            }

            ++$agg[$key]['hospital_count'];
            $agg[$key]['allocations_sum'] += $alloc;
            $agg[$key]['allocations_min'] = null === $agg[$key]['allocations_min']
                ? $alloc
                : min($agg[$key]['allocations_min'], $alloc);
            $agg[$key]['allocations_max'] = null === $agg[$key]['allocations_max']
                ? $alloc
                : max($agg[$key]['allocations_max'], $alloc);

            if (null !== $beds) {
                $agg[$key]['beds_sum'] += $beds;
                ++$agg[$key]['beds_count'];
                $agg[$key]['beds_min'] = null === $agg[$key]['beds_min']
                    ? $beds
                    : min($agg[$key]['beds_min'], $beds);
                $agg[$key]['beds_max'] = null === $agg[$key]['beds_max']
                    ? $beds
                    : max($agg[$key]['beds_max'], $beds);
            }
        }

        $out = [];
        foreach ($agg as $cell) {
            $hospitalCount = $cell['hospital_count'];
            $allocSum = $cell['allocations_sum'];
            $avgAlloc = $hospitalCount > 0 ? ($allocSum / $hospitalCount) : 0.0;
            $avgBeds = $cell['beds_count'] > 0 ? ($cell['beds_sum'] / $cell['beds_count']) : 0.0;

            $value = match ($measure) {
                HospitalPivotMeasure::HospitalCount, HospitalPivotMeasure::RowPercent => (float) $hospitalCount,
                HospitalPivotMeasure::AvgBeds => (float) $avgBeds,
                HospitalPivotMeasure::MinBeds => (float) ($cell['beds_min'] ?? 0),
                HospitalPivotMeasure::MaxBeds => (float) ($cell['beds_max'] ?? 0),
                HospitalPivotMeasure::TotalAllocations => (float) $allocSum,
                HospitalPivotMeasure::AvgAllocations => (float) $avgAlloc,
                HospitalPivotMeasure::MinAllocations => (float) ($cell['allocations_min'] ?? 0),
                HospitalPivotMeasure::MaxAllocations => (float) ($cell['allocations_max'] ?? 0),
            };

            $out[] = [
                'row_key' => $cell['row_key'],
                'col_key' => $cell['col_key'],
                'value' => $value,
            ];
        }

        return $out;
    }
}
