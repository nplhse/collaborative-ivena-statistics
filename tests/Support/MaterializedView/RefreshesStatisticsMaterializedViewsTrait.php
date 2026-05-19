<?php

declare(strict_types=1);

namespace App\Tests\Support\MaterializedView;

use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Refreshes overview statistics materialized views after test fixtures were created.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait RefreshesStatisticsMaterializedViewsTrait
{
    protected function refreshStatisticsMaterializedViews(): void
    {
        self::getContainer()->get(MaterializedViewRefresher::class)->refresh(
            [StatisticsMaterializedViewGroups::OVERVIEW],
            concurrently: false,
        );
    }
}
