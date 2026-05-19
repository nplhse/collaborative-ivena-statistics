<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\MaterializedView;

use Doctrine\DBAL\Connection;

/**
 * Drops statistics materialized views so Doctrine schema tooling can reset the database.
 *
 * Used from the test environment only (Foundry ORM reset decorator).
 */
final readonly class StatisticsMaterializedViewDropper
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function dropAllForSchemaReset(): void
    {
        foreach (StatisticsMaterializedViewGroups::allViews() as $viewName) {
            $this->connection->executeStatement(
                sprintf('DROP MATERIALIZED VIEW IF EXISTS %s CASCADE', $viewName),
            );
        }
    }
}
