<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

final class ProjectionFilterApplier
{
    public function applyCreatedAtRange(
        QueryBuilder $qb,
        string $field,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): void {
        $qb->andWhere(sprintf('%s >= :from', $field))
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);

        if ($toExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere(sprintf('%s < :toExclusive', $field))
                ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
        }
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public function applyHospitalScope(QueryBuilder $qb, string $field, ?array $hospitalIds): void
    {
        if (null === $hospitalIds) {
            return;
        }

        $qb->andWhere(sprintf('%s IN (:hospitalIds)', $field))
            ->setParameter('hospitalIds', $hospitalIds);
    }
}
