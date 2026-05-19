<?php

declare(strict_types=1);

namespace App\Tests\Support\Foundry;

use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewDropper;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\ORM\ResetDatabase\OrmResetter;

/**
 * Drops statistics materialized views before Foundry's schema reset and recreates them afterwards.
 *
 * @see tests/Support/MaterializedView/README.md
 */
final readonly class MaterializedViewAwareOrmResetter implements OrmResetter
{
    public function __construct(
        private OrmResetter $decorated,
        private StatisticsMaterializedViewDropper $materializedViewDropper,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    public function resetBeforeFirstTest(KernelInterface $kernel): void
    {
        $this->prepareSchemaReset();
        $this->decorated->resetBeforeFirstTest($kernel);
        $this->finishSchemaReset();
    }

    public function resetBeforeEachTest(KernelInterface $kernel): void
    {
        $this->prepareSchemaReset();
        $this->decorated->resetBeforeEachTest($kernel);
        $this->finishSchemaReset();
    }

    private function prepareSchemaReset(): void
    {
        $this->materializedViewDropper->dropAllForSchemaReset();
        $this->materializedViewsInstaller->resetInstallationState();
    }

    private function finishSchemaReset(): void
    {
        $this->materializedViewsInstaller->ensureInstalled();
    }
}
