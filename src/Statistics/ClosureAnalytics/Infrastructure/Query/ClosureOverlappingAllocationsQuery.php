<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureOverlappingAllocationRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureOverlapWindow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final readonly class ClosureOverlappingAllocationsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<ClosureOverlapWindow> $windows
     *
     * @return list<ClosureOverlappingAllocationRow>
     */
    public function fetch(array $windows): array
    {
        if ([] === $windows) {
            return [];
        }

        $selects = [];
        $params = [];
        $types = [];
        foreach ($windows as $index => $window) {
            $selects[] = sprintf(
                'SELECT CAST(:i%d AS INTEGER) AS interval_id, CAST(:h%d AS INTEGER) AS hospital_id, CAST(:d%d AS INTEGER) AS department_id, CAST(:s%d AS TIMESTAMPTZ) AS starts_at, CAST(:e%d AS TIMESTAMPTZ) AS ends_at',
                $index,
                $index,
                $index,
                $index,
                $index,
            );
            $params['i'.$index] = $window->analysisIntervalId;
            $params['h'.$index] = $window->hospitalId;
            $params['d'.$index] = $window->departmentId;
            $params['s'.$index] = $window->startsAt;
            $params['e'.$index] = $window->endsAt;
            $types['i'.$index] = ParameterType::INTEGER;
            $types['h'.$index] = ParameterType::INTEGER;
            $types['d'.$index] = ParameterType::INTEGER;
            $types['s'.$index] = Types::DATETIMETZ_IMMUTABLE;
            $types['e'.$index] = Types::DATETIMETZ_IMMUTABLE;
        }

        $windowsSql = implode("\nUNION ALL\n", $selects);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH windows AS (
{$windowsSql}
)
SELECT DISTINCT a.id, a.public_id, a.created_at, a.urgency,
       d.id AS department_id, d.name AS department_name,
       COALESCE(inor.name, iraw.name) AS indication_name
FROM allocation a
JOIN windows w
    ON a.hospital_id = w.hospital_id
   AND a.department_id = w.department_id
   AND (a.created_at AT TIME ZONE 'Europe/Berlin') >= w.starts_at
   AND (a.created_at AT TIME ZONE 'Europe/Berlin') < w.ends_at
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = w.interval_id
   AND (
       (cl.care_level = 'emergency' AND a.urgency = 1)
       OR (cl.care_level = 'inpatient' AND a.urgency = 2)
       OR (cl.care_level = 'outpatient' AND a.urgency = 3)
       OR (cl.care_level NOT IN ('emergency', 'inpatient', 'outpatient') AND a.urgency IN (1, 2, 3))
   )
JOIN department d ON d.id = a.department_id
JOIN indication_raw iraw ON iraw.id = a.indication_raw_id
LEFT JOIN indication_normalized inor ON inor.id = a.indication_normalized_id
ORDER BY a.created_at ASC, a.id ASC
SQL, $params, $types);

        return array_map($this->row(...), $rows);
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function row(array $row): ClosureOverlappingAllocationRow
    {
        $urgency = AllocationUrgency::tryFrom((int) $row['urgency']);
        if (!$urgency instanceof AllocationUrgency) {
            $urgency = AllocationUrgency::INPATIENT;
        }

        return new ClosureOverlappingAllocationRow(
            (string) $row['public_id'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('Europe/Berlin')),
            (int) $row['department_id'],
            (string) $row['department_name'],
            null === $row['indication_name'] || '' === $row['indication_name']
                ? null
                : (string) $row['indication_name'],
            $urgency,
        );
    }
}
