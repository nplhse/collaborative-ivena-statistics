<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Admin;

use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * MTS diagrams of the catalog in the back office (ADR 032). Linked from the admin menu by route name: no other context
 * may use Encounter classes.
 *
 * @extends AbstractMtsCatalogItemCrudController<MtsDiagram>
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/encounter/mts-diagrams', name: 'encounter_mts_diagram')]
final class MtsDiagramCrudController extends AbstractMtsCatalogItemCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MtsDiagram::class;
    }

    #[\Override]
    protected function kind(): MtsCatalogKind
    {
        return MtsCatalogKind::Diagram;
    }

    #[\Override]
    protected function newEntry(): MtsDiagram
    {
        return new MtsDiagram('', '', MtsCatalogSource::Manual, MtsCatalogStatus::Curated);
    }
}
