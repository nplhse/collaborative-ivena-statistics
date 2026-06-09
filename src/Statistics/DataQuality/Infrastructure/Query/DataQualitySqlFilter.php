<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Builds SQL WHERE fragments for evidence reads on allocation_stats_projection.
 */
final class DataQualitySqlFilter
{
    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    public static function buildWhere(
        ?int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        string $tableAlias = '',
    ): array {
        $prefix = '' === $tableAlias ? '' : $tableAlias.'.';
        $conditions = ['1 = 1'];
        $params = [];
        /** @var array<string, ArrayParameterType> $types */
        $types = [];

        if (null !== $indicationId) {
            $conditions[] = sprintf('%sindication_normalized_id = :indication_id', $prefix);
            $params['indication_id'] = $indicationId;
        }

        if ($from instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at >= :from', $prefix);
            $params['from'] = $from->format('Y-m-d H:i:s');
        }

        if ($toExclusive instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%screated_at < :to_exclusive', $prefix);
            $params['to_exclusive'] = $toExclusive->format('Y-m-d H:i:s');
        }

        if (\is_array($scope->hospitalIds)) {
            $ids = array_map(static fn (int $id): int => $id, $scope->hospitalIds);
            if ([] === $ids) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = sprintf('%shospital_id IN (:hospital_ids)', $prefix);
                $params['hospital_ids'] = $ids;
                $types['hospital_ids'] = ArrayParameterType::INTEGER;
            }
        }

        if (null !== $scope->locationCodes) {
            $locationCodes = array_map(intval(...), $scope->locationCodes);
            $conditions[] = sprintf('%shospital_location_code IN (:location_codes)', $prefix);
            $params['location_codes'] = $locationCodes;
            $types['location_codes'] = ArrayParameterType::INTEGER;
        }

        if (null !== $scope->tierCodes) {
            $tierCodes = array_map(intval(...), $scope->tierCodes);
            $conditions[] = sprintf('%shospital_tier_code IN (:tier_codes)', $prefix);
            $params['tier_codes'] = $tierCodes;
            $types['tier_codes'] = ArrayParameterType::INTEGER;
        }

        return [implode(' AND ', $conditions), $params, $types];
    }
}
