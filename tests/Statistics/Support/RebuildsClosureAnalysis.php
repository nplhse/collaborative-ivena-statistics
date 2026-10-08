<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureAnalysisRebuilder;
use Doctrine\DBAL\Connection;

trait RebuildsClosureAnalysis
{
    protected function rebuildClosureAnalysis(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE import SET status = 'Completed' WHERE id IN (SELECT DISTINCT import_id FROM closure_interval)",
        );
        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild();
    }

    protected function closureEventId(int $hospitalId, string $sourceGroupId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM closure_event WHERE hospital_id = :hospital AND source_group_id = :group_id',
            ['hospital' => $hospitalId, 'group_id' => $sourceGroupId],
        );
    }
}
