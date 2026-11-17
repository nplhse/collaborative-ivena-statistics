<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Http\Controller\Admin;

use Cois\Plugin\EdVisit\Domain\Entity\MtsIndicator;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogSource;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * MTS indicators of the catalog in the back office (ADR 032). Linked from the admin menu by route name: no other context
 * may use Encounter classes.
 *
 * @extends AbstractMtsCatalogItemCrudController<MtsIndicator>
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/encounter/mts-indicators', name: 'encounter_mts_indicator')]
final class MtsIndicatorCrudController extends AbstractMtsCatalogItemCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MtsIndicator::class;
    }

    #[\Override]
    protected function kind(): MtsCatalogKind
    {
        return MtsCatalogKind::Indicator;
    }

    #[\Override]
    protected function newEntry(): MtsIndicator
    {
        return new MtsIndicator('', '', MtsCatalogSource::Manual, MtsCatalogStatus::Curated);
    }
}
