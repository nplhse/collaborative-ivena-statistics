<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerQueryMapperRegistry;
use App\Statistics\AnalysisExplorer\Application\HospitalsDistributionResultMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalDistributionSqlBuilder;
use Doctrine\DBAL\Connection;

final readonly class HospitalsDistributionQuery
{
    public function __construct(
        private Connection $connection,
        private ExplorerQueryMapperRegistry $queryMapperRegistry,
        private GenericHospitalDistributionSqlBuilder $sqlBuilder,
        private HospitalsDistributionResultMapper $resultMapper,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    /**
     * @return list<AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        $profile = $this->profileRegistry->profileFor($query->visualMetricKey);
        if (!$profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return [];
        }

        $gaQuery = $this->queryMapperRegistry->map($query);
        [$sql, $params, $types] = $this->sqlBuilder->build($gaQuery, $profile->valueSource);

        /** @var list<array{bucket: mixed, value: mixed}> $rawRows */
        $rawRows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return $this->resultMapper->map($rawRows, $gaQuery, $query);
    }
}
