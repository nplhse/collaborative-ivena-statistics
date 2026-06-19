<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

final class ProjectionFilterApplier
{
    public function applyCreatedAtRange(
        QueryBuilder $qb,
        string $field,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): void {
        if ($from instanceof \DateTimeImmutable) {
            $qb->andWhere(sprintf('%s >= :from', $field))
                ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        }

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

        if ([] === $hospitalIds) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere(sprintf('%s IN (:hospitalIds)', $field))
            ->setParameter('hospitalIds', $hospitalIds);
    }

    public function applyScopeCriteria(
        QueryBuilder $qb,
        string $hospitalIdField,
        StatisticsScopeCriteria $criteria,
        ?string $locationCodeField = null,
        ?string $tierCodeField = null,
    ): void {
        $this->applyHospitalScope($qb, $hospitalIdField, $criteria->hospitalIds);

        if (null !== $locationCodeField && null !== $criteria->locationCodes) {
            $qb->andWhere(sprintf('%s IN (:cohortLocationCodes)', $locationCodeField))
                ->setParameter('cohortLocationCodes', $criteria->locationCodes);
        }

        if (null !== $tierCodeField && null !== $criteria->tierCodes) {
            $qb->andWhere(sprintf('%s IN (:cohortTierCodes)', $tierCodeField))
                ->setParameter('cohortTierCodes', $criteria->tierCodes);
        }
    }
}
