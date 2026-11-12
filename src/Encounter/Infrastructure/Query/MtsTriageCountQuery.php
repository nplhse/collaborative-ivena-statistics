<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\MtsTriageCountReaderInterface;
use App\Encounter\Application\Glossary\EncounterHospitalScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Counts of the initial MTS triage per code for the MTS glossary (#1056), see MtsTriageCountReaderInterface.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of MtsTriageCountReaderInterface.
 */
final readonly class MtsTriageCountQuery implements MtsTriageCountReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function countByCategory(EncounterHospitalScope $scope): array
    {
        return $this->countBy('mts_category', $scope);
    }

    #[\Override]
    public function countByDiagram(EncounterHospitalScope $scope): array
    {
        return $this->countBy('mts_diagram', $scope);
    }

    #[\Override]
    public function countByIndicator(EncounterHospitalScope $scope): array
    {
        return $this->countBy('mts_indicator', $scope);
    }

    #[\Override]
    public function countIndicatorsOfDiagram(EncounterHospitalScope $scope, string $diagram): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        [$hospitalCondition, $parameters, $types] = $this->hospitalCondition($scope);
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT COALESCE(mts_indicator, \'\') AS code, COUNT(*) AS encounters FROM encounter'
            .' WHERE mts_diagram = :diagram'.$hospitalCondition
            .' GROUP BY code ORDER BY code',
            ['diagram' => $diagram] + $parameters,
            $types,
        );

        return $this->toCounts($rows);
    }

    /**
     * @param 'mts_category'|'mts_diagram'|'mts_indicator' $column
     *
     * @return array<string, int>
     */
    private function countBy(string $column, EncounterHospitalScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        [$hospitalCondition, $parameters, $types] = $this->hospitalCondition($scope);
        $rows = $this->connection->fetchAllKeyValue(
            sprintf('SELECT %1$s AS code, COUNT(*) AS encounters FROM encounter WHERE %1$s IS NOT NULL%2$s GROUP BY %1$s ORDER BY %1$s', $column, $hospitalCondition),
            $parameters,
            $types,
        );

        return $this->toCounts($rows);
    }

    /**
     * @return array{string, array<string, list<int>>, array<string, ArrayParameterType>}
     */
    private function hospitalCondition(EncounterHospitalScope $scope): array
    {
        if ($scope->allHospitals) {
            return ['', [], []];
        }

        return [
            ' AND hospital_id IN (:hospitals)',
            ['hospitals' => $scope->hospitalIds],
            ['hospitals' => ArrayParameterType::INTEGER],
        ];
    }

    /**
     * @param array<mixed, mixed> $rows
     *
     * @return array<string, int>
     */
    private function toCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $code => $count) {
            $counts[(string) $code] = (int) $count;
        }

        return $counts;
    }
}
