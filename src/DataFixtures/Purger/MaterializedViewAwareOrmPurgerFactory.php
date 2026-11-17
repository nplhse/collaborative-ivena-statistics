<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewDropper;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Cois\Plugin\EdVisit\Application\Plausibility\PlausibilityCuration;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Bundle\FixturesBundle\Purger\PurgerFactory;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/** @implements PurgerFactory<MaterializedViewAwareOrmPurger> */
final readonly class MaterializedViewAwareOrmPurgerFactory implements PurgerFactory
{
    private const array MTS_CATALOG_TABLES = [
        'encounter_mts_diagram',
        'encounter_mts_indicator',
        'encounter_mts_alias',
        'encounter_mts_diagram_indicator',
    ];

    /**
     * The curated problem areas of the plausibility table (ADR 033). Snapshots and their cells are derived from them
     * and from the purged data, so they are purged; the purger rebuilds the seed draft afterwards.
     */
    private const array PLAUSIBILITY_AREA_TABLES = [
        'encounter_plausibility_area',
        'encounter_plausibility_area_code',
        'encounter_plausibility_area_diagram',
    ];

    public function __construct(
        private ORMPurgerFactory $inner,
        private StatisticsMaterializedViewDropper $materializedViewDropper,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
        private PlausibilityCuration $plausibilityCuration,
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
            // The MTS catalog is seeded by its migration and maintained in the app (ADR 032); fixtures keep it.
            ...self::MTS_CATALOG_TABLES,
            // The problem areas are reviewed in the app on development and staging (ADR 033, #1219); fixtures keep them.
            ...self::PLAUSIBILITY_AREA_TABLES,
        ]));

        return new MaterializedViewAwareOrmPurger(
            $this->inner->createForEntityManager($emName, $em, $excluded, $purgeWithTruncate),
            $this->materializedViewDropper,
            $this->materializedViewsInstaller,
            $this->plausibilityCuration,
        );
    }
}
