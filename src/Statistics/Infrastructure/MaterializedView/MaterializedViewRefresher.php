<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\MaterializedView;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class MaterializedViewRefresher
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $groups empty = all registered groups
     *
     * @return list<array{view: string, success: bool, message: string}>
     */
    public function refresh(array $groups = [], bool $concurrently = true): array
    {
        $results = [];
        foreach (StatisticsMaterializedViewGroups::viewsForGroups($groups) as $viewName) {
            $results[] = $this->refreshView($viewName, $concurrently);
        }

        return $results;
    }

    /**
     * @return array{view: string, success: bool, message: string}
     */
    private function refreshView(string $viewName, bool $concurrently): array
    {
        $sql = $concurrently
            ? sprintf('REFRESH MATERIALIZED VIEW CONCURRENTLY %s', $viewName)
            : sprintf('REFRESH MATERIALIZED VIEW %s', $viewName);

        try {
            $this->connection->executeStatement($sql);

            return [
                'view' => $viewName,
                'success' => true,
                'message' => sprintf('Refreshed %s', $viewName),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('statistics_materialized_view.refresh_failed', [
                'view' => $viewName,
                'exception' => $exception,
            ]);

            return [
                'view' => $viewName,
                'success' => false,
                'message' => sprintf('Failed to refresh %s: %s', $viewName, $exception->getMessage()),
            ];
        }
    }
}
