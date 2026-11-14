<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

use App\Encounter\Application\Plausibility\PlausibilityCuration;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewDropper;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MaterializedViewAwareOrmPurger implements ORMPurgerInterface
{
    public function __construct(
        private ORMPurgerInterface $inner,
        private StatisticsMaterializedViewDropper $materializedViewDropper,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
        private PlausibilityCuration $plausibilityCuration,
    ) {
    }

    #[\Override]
    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->inner->setEntityManager($em);
    }

    #[\Override]
    public function purge(): void
    {
        $this->materializedViewDropper->dropAllForSchemaReset();
        $this->materializedViewsInstaller->resetInstallationState();
        $this->inner->purge();
        // The problem areas survive the purge, their snapshots do not (#1219): rebuild the seed draft from the kept
        // areas. It only reads the kept tables and runs in the executor's transaction; nothing is activated.
        $this->plausibilityCuration->rebuildSeedDraft();
        $this->materializedViewsInstaller->ensureInstalled();
    }
}
