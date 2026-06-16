<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewDropper;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Bundle\FixturesBundle\Purger\PurgerFactory;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/** @implements PurgerFactory<MaterializedViewAwareOrmPurger> */
final readonly class MaterializedViewAwareOrmPurgerFactory implements PurgerFactory
{
    public function __construct(
        private ORMPurgerFactory $inner,
        private StatisticsMaterializedViewDropper $materializedViewDropper,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    #[\Override]
    public function createForEntityManager(
        ?string $emName,
        EntityManagerInterface $em,
        array $excluded = [],
        bool $purgeWithTruncate = false,
    ): ORMPurgerInterface {
        $excluded = array_values(array_unique([
            ...$excluded,
            ...StatisticsMaterializedViewGroups::allViews(),
        ]));

        return new MaterializedViewAwareOrmPurger(
            $this->inner->createForEntityManager($emName, $em, $excluded, $purgeWithTruncate),
            $this->materializedViewDropper,
            $this->materializedViewsInstaller,
        );
    }
}
