<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Doctrine\DBAL\Connection;

/**
 * Ensures overview materialized views exist (required when the test DB is reset via schema tooling).
 */
final class OverviewMaterializedViewsInstaller
{
    private static bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly MaterializedViewRefresher $materializedViewRefresher,
        private readonly string $kernelEnvironment,
    ) {
    }

    public function resetInstallationState(): void
    {
        self::$ensured = false;
    }

    public function ensureInstalled(): void
    {
        if (self::$ensured) {
            return;
        }

        $overviewViews = StatisticsMaterializedViewGroups::viewsForGroup(StatisticsMaterializedViewGroups::OVERVIEW);
        $probeView = $overviewViews[0];

        if ($this->isMaterializedViewInstalled($probeView)) {
            self::$ensured = true;

            return;
        }

        if ($this->isWrongRelationKind($probeView)) {
            foreach ($overviewViews as $viewName) {
                $this->dropWrongRelation($viewName);
            }
        }

        if ('test' !== $this->kernelEnvironment) {
            throw new \RuntimeException('Overview materialized views are missing. Run doctrine migrations or app:statistics:refresh-mviews --overview.');
        }

        $this->connection->executeStatement(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_state_hospital_count AS
SELECT state_id, COUNT(DISTINCT hospital_id)::int AS hospital_count
FROM allocation_stats_projection
WHERE state_id IS NOT NULL
GROUP BY state_id
SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_mv_projection_state_hospital_count_state ON mv_projection_state_hospital_count (state_id)');

        $this->connection->executeStatement(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_dispatch_area_hospital_count AS
SELECT dispatch_area_id, COUNT(DISTINCT hospital_id)::int AS hospital_count
FROM allocation_stats_projection
WHERE dispatch_area_id IS NOT NULL
GROUP BY dispatch_area_id
SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_mv_projection_dispatch_area_hospital_count_area ON mv_projection_dispatch_area_hospital_count (dispatch_area_id)');

        $this->connection->executeStatement(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_hospital_dimensions AS
SELECT
    hospital_id,
    MIN(state_id) AS state_id,
    MIN(dispatch_area_id) AS dispatch_area_id,
    MIN(hospital_location_code) AS hospital_location_code,
    MIN(hospital_tier_code) AS hospital_tier_code
FROM allocation_stats_projection
GROUP BY hospital_id
SQL);
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_mv_projection_hospital_dimensions_hospital ON mv_projection_hospital_dimensions (hospital_id)');

        self::$ensured = true;
    }

    public function refreshIfInstalled(): void
    {
        $this->ensureInstalled();

        $overviewViews = StatisticsMaterializedViewGroups::viewsForGroup(StatisticsMaterializedViewGroups::OVERVIEW);
        if (!$this->isMaterializedViewInstalled($overviewViews[0])) {
            return;
        }

        $this->materializedViewRefresher->refresh(
            [StatisticsMaterializedViewGroups::OVERVIEW],
            concurrently: false,
        );
    }

    private function isMaterializedViewInstalled(string $viewName): bool
    {
        return 'm' === $this->relationKind($viewName);
    }

    private function isWrongRelationKind(string $viewName): bool
    {
        $kind = $this->relationKind($viewName);

        return null !== $kind && 'm' !== $kind;
    }

    private function relationKind(string $relationName): ?string
    {
        $kind = $this->connection->fetchOne(
            'SELECT relkind FROM pg_class WHERE oid = to_regclass(:relation)',
            ['relation' => $relationName],
        );

        return \is_string($kind) ? $kind : null;
    }

    private function dropWrongRelation(string $relationName): void
    {
        if (null === $this->relationKind($relationName)) {
            return;
        }

        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s CASCADE', $relationName));
    }
}
