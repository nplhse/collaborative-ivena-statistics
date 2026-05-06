<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Pivot\AllocationPivotDimension;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class AllocationPivotQuery
{
    private const string AGE_BUCKET_CASE = <<<'DQL'
CASE WHEN a.age IS NULL THEN 'unknown' WHEN a.age <= 18 THEN '0_18' WHEN a.age <= 29 THEN '19_29' WHEN a.age <= 39 THEN '30_39' WHEN a.age <= 49 THEN '40_49' WHEN a.age <= 59 THEN '50_59' WHEN a.age <= 69 THEN '60_69' WHEN a.age <= 79 THEN '70_79' WHEN a.age <= 89 THEN '80_89' WHEN a.age <= 99 THEN '90_99' ELSE '100p' END
DQL;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{row_key: string, col_key: string, value: float, row_label?: string, col_label?: string}>
     */
    public function fetchCells(
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        AllocationPivotDimension $rows,
        AllocationPivotDimension $cols,
    ): array {
        if (\is_array($hospitalIds) && [] === $hospitalIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);

        if ($toExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere('a.createdAt < :toExclusive')
                ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        [$rowExpr, $rowLabelExpr] = $this->dimensionExpression($rows, $qb, 'r');
        [$colExpr, $colLabelExpr] = $this->dimensionExpression($cols, $qb, 'c');

        $qb->select(sprintf('%s AS row_key', $rowExpr))
            ->addSelect(sprintf('%s AS col_key', $colExpr))
            ->addSelect('COUNT(a.id) AS cnt')
            ->groupBy('row_key', 'col_key');

        if (null !== $rowLabelExpr) {
            $qb->addSelect(sprintf('%s AS row_label', $rowLabelExpr));
        }
        if (null !== $colLabelExpr) {
            $qb->addSelect(sprintf('%s AS col_label', $colLabelExpr));
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($raw as $row) {
            $rowKeyRaw = $row['row_key'] ?? '';
            $colKeyRaw = $row['col_key'] ?? '';
            $item = [
                'row_key' => $this->scalarKey($rowKeyRaw),
                'col_key' => $this->scalarKey($colKeyRaw),
                'value' => (float) ($row['cnt'] ?? 0),
            ];
            if (isset($row['row_label'])) {
                $item['row_label'] = (string) $row['row_label'];
            }
            if (isset($row['col_label'])) {
                $item['col_label'] = (string) $row['col_label'];
            }
            $out[] = $item;
        }

        return $out;
    }

    private function scalarKey(mixed $value): string
    {
        if ($value instanceof AllocationGender) {
            return $value->value;
        }
        if ($value instanceof AllocationUrgency) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    /**
     * @return array{string, ?string}
     */
    private function dimensionExpression(AllocationPivotDimension $dimension, QueryBuilder $qb, string $prefix): array
    {
        return match ($dimension) {
            AllocationPivotDimension::Gender => ['a.gender', null],
            AllocationPivotDimension::Urgency => ['a.urgency', null],
            AllocationPivotDimension::AgeGroup => [self::AGE_BUCKET_CASE, null],
            AllocationPivotDimension::Department => $this->departmentExpression($qb, $prefix),
        };
    }

    /**
     * @return array{string, string}
     */
    private function departmentExpression(QueryBuilder $qb, string $prefix): array
    {
        $alias = $prefix.'Dept';
        $qb->leftJoin('a.department', $alias);

        return [sprintf('%s.id', $alias), sprintf('%s.name', $alias)];
    }
}
