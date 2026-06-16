<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

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
        $this->materializedViewsInstaller->ensureInstalled();
    }
}
