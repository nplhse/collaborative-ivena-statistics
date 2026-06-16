<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure\Export;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use Doctrine\DBAL\Connection;

final readonly class AllocationStatsPatternQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countRows(HospitalTier $tier, HospitalLocation $location): int
    {
        $tierCode = AllocationStatsHospitalTierProjectionCode::tryFromHospitalTier($tier)?->value;
        $locationCode = AllocationStatsHospitalLocationProjectionCode::tryFromHospitalLocation($location)?->value;
        if (null === $tierCode || null === $locationCode) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection WHERE hospital_tier_code = :tier AND hospital_location_code = :location',
            ['tier' => $tierCode, 'location' => $locationCode],
        );

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportDistributions(HospitalTier $tier, HospitalLocation $location): array
    {
        $tierCode = AllocationStatsHospitalTierProjectionCode::tryFromHospitalTier($tier)?->value;
        $locationCode = AllocationStatsHospitalLocationProjectionCode::tryFromHospitalLocation($location)?->value;
        if (null === $tierCode || null === $locationCode) {
            throw new \RuntimeException('Cannot export pattern for invalid segment.');
        }

        $params = ['tier' => $tierCode, 'location' => $locationCode];
        $where = 'WHERE p.hospital_tier_code = :tier AND p.hospital_location_code = :location';

        return [
            'urgency' => $this->frequencyByCode($where, $params, 'p.urgency_code'),
            'gender' => $this->genderDistribution($where, $params),
            'transport_type' => $this->transportTypeDistribution($where, $params),
            'department' => $this->frequencyByName($where, $params, 'department', 'd.name'),
            'speciality' => $this->frequencyByName($where, $params, 'speciality', 's.name'),
            'assignment' => $this->frequencyByName($where, $params, 'assignment', 'a.name'),
            'occasion' => $this->frequencyByName($where, $params, 'occasion', 'o.name', true),
            'infection' => $this->nullableFrequencyByName($where, $params, 'infection', 'i.name'),
            'indication_normalized' => $this->nullableFrequencyByName($where, $params, 'indication_normalized', 'ind_norm.name'),
            'secondary_transport' => $this->secondaryTransportDistribution($where, $params),
            'age_bucket' => $this->ageBucketDistribution($where, $params),
            'hour_of_day' => $this->frequencyByCode($where, $params, 'p.created_hour'),
            'flags' => $this->flagProbabilities($where, $params),
            'transport_time_minutes' => $this->transportTimePercentiles($where, $params),
        ];
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function frequencyByCode(string $where, array $params, string $column): array
    {
        /** @var list<array{label: string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s::TEXT AS label, COUNT(*) AS cnt FROM allocation_stats_projection p %s GROUP BY 1 ORDER BY cnt DESC', $column, $where),
            $params,
        );

        return $this->toProbabilities($rows);
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function frequencyByName(
        string $where,
        array $params,
        string $alias,
        string $nameColumn,
        bool $nullable = false,
    ): array {
        $join = match ($alias) {
            'department' => 'JOIN department d ON d.id = p.department_id',
            'speciality' => 'JOIN speciality s ON s.id = p.speciality_id',
            'assignment' => 'JOIN assignment a ON a.id = p.assignment_id',
            'occasion' => 'LEFT JOIN occasion o ON o.id = p.occasion_id',
            default => throw new \InvalidArgumentException('Unsupported alias.'),
        };

        if ($nullable) {
            return $this->nullableFrequencyByName($where, $params, $alias, str_replace($alias.'.', '', $nameColumn));
        }

        /** @var list<array{label: string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s AS label, COUNT(*) AS cnt FROM allocation_stats_projection p %s %s GROUP BY 1 ORDER BY cnt DESC', $nameColumn, $join, $where),
            $params,
        );

        return $this->toProbabilities($rows);
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float|int>
     */
    private function nullableFrequencyByName(string $where, array $params, string $key, string $nameColumn): array
    {
        $join = match ($key) {
            'occasion' => 'LEFT JOIN occasion o ON o.id = p.occasion_id',
            'infection' => 'LEFT JOIN infection i ON i.id = p.infection_id',
            'indication_normalized' => 'LEFT JOIN indication_normalized ind_norm ON ind_norm.id = p.indication_normalized_id',
            default => throw new \InvalidArgumentException('Unsupported nullable key.'),
        };

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM allocation_stats_projection p %s %s', $join, $where),
            $params,
        );
        if (0 === $total) {
            return ['_present' => 0.0];
        }

        /** @var list<array{label: ?string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s AS label, COUNT(*) AS cnt FROM allocation_stats_projection p %s %s GROUP BY 1 ORDER BY cnt DESC', $nameColumn, $join, $where),
            $params,
        );

        $present = 0;
        $distribution = [];
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $label = $row['label'];
            if (!\is_string($label) || '' === $label) {
                continue;
            }
            $present += $count;
            $distribution[$label] = $count;
        }

        $result = ['_present' => $present / $total];
        foreach ($this->normalizeCounts($distribution) as $label => $probability) {
            $result[$label] = $probability;
        }

        return $result;
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float|int>
     */
    private function secondaryTransportDistribution(string $where, array $params): array
    {
        $tier = $params['tier'];
        $location = $params['location'];

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection p '.$where,
            $params,
        );
        if (0 === $total) {
            return ['_present' => 0.0];
        }

        /** @var list<array{label: ?string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT st.name AS label, COUNT(*) AS cnt
FROM allocation_stats_projection p
JOIN allocation al ON al.id = p.id
LEFT JOIN secondary_transport st ON st.id = al.secondary_transport_id
WHERE p.hospital_tier_code = :tier AND p.hospital_location_code = :location
GROUP BY 1
ORDER BY cnt DESC
SQL,
            ['tier' => $tier, 'location' => $location],
        );

        $present = 0;
        $distribution = [];
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $label = $row['label'];
            if (!\is_string($label) || '' === $label) {
                continue;
            }
            $present += $count;
            $distribution[$label] = $count;
        }

        $result = ['_present' => $present / $total];
        foreach ($this->normalizeCounts($distribution) as $label => $probability) {
            $result[$label] = $probability;
        }

        return $result;
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function genderDistribution(string $where, array $params): array
    {
        /** @var list<array{label: ?string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(<<<SQL
SELECT CASE p.gender_code
    WHEN 1 THEN 'M'
    WHEN 2 THEN 'F'
    WHEN 3 THEN 'X'
    ELSE 'X'
END AS label, COUNT(*) AS cnt
FROM allocation_stats_projection p
%s
GROUP BY 1
ORDER BY cnt DESC
SQL, $where),
            $params,
        );

        return $this->toProbabilities($rows);
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function transportTypeDistribution(string $where, array $params): array
    {
        /** @var list<array{label: ?string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(<<<SQL
SELECT CASE p.transport_type_code
    WHEN 1 THEN 'G'
    WHEN 2 THEN 'A'
    ELSE 'G'
END AS label, COUNT(*) AS cnt
FROM allocation_stats_projection p
%s
GROUP BY 1
ORDER BY cnt DESC
SQL, $where),
            $params,
        );

        return $this->toProbabilities($rows);
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function ageBucketDistribution(string $where, array $params): array
    {
        /** @var list<array{label: string, cnt: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(<<<SQL
SELECT CASE
    WHEN p.age BETWEEN 0 AND 17 THEN '0-17'
    WHEN p.age BETWEEN 18 AND 39 THEN '18-39'
    WHEN p.age BETWEEN 40 AND 64 THEN '40-64'
    ELSE '65-99'
END AS label, COUNT(*) AS cnt
FROM allocation_stats_projection p
%s
GROUP BY 1
ORDER BY cnt DESC
SQL, $where),
            $params,
        );

        return $this->toProbabilities($rows);
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function flagProbabilities(string $where, array $params): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection p '.$where,
            $params,
        );
        if (0 === $total) {
            return [];
        }

        $flags = [
            'requires_resus',
            'requires_cathlab',
            'is_cpr',
            'is_ventilated',
            'is_shock',
            'is_pregnant',
            'is_work_accident',
            'is_with_physician',
        ];

        $result = [];
        foreach ($flags as $flag) {
            $count = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM allocation_stats_projection p %s AND p.%s = true', $where, $flag),
                $params,
            );
            $result[$flag] = $count / $total;
        }

        $closedCount = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM allocation_stats_projection p JOIN allocation al ON al.id = p.id %s AND al.department_was_closed = true', $where),
            $params,
        );
        $result['department_was_closed'] = $closedCount / $total;

        return $result;
    }

    /**
     * @param array<string, int> $params
     *
     * @return array<string, float>
     */
    private function transportTimePercentiles(string $where, array $params): array
    {
        /** @var array{p25: float|int|string|null, p50: float|int|string|null, p75: float|int|string|null, p90: float|int|string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            sprintf(<<<SQL
SELECT
    percentile_cont(0.25) WITHIN GROUP (ORDER BY p.transport_time_minutes) AS p25,
    percentile_cont(0.50) WITHIN GROUP (ORDER BY p.transport_time_minutes) AS p50,
    percentile_cont(0.75) WITHIN GROUP (ORDER BY p.transport_time_minutes) AS p75,
    percentile_cont(0.90) WITHIN GROUP (ORDER BY p.transport_time_minutes) AS p90
FROM allocation_stats_projection p
%s
SQL, $where),
            $params,
        );

        if (!\is_array($row)) {
            return [];
        }

        return [
            'p25' => round((float) $row['p25'], 1),
            'p50' => round((float) $row['p50'], 1),
            'p75' => round((float) $row['p75'], 1),
            'p90' => round((float) $row['p90'], 1),
        ];
    }

    /**
     * @param list<array{label: ?string, cnt: string|int}> $rows
     *
     * @return array<string, float>
     */
    private function toProbabilities(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $label = $row['label'];
            if (!\is_string($label) || '' === $label) {
                continue;
            }
            $counts[$label] = (int) $row['cnt'];
        }

        return $this->normalizeCounts($counts);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, float>
     */
    private function normalizeCounts(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return [];
        }

        $probabilities = [];
        foreach ($counts as $label => $count) {
            $probabilities[$label] = $count / $total;
        }

        return $probabilities;
    }
}
