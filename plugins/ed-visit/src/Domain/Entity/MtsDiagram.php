<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * An MTS diagram of the catalog (ADR 032). Encounters store its code as a plain string, without a foreign key.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_mts_diagram')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_mts_diagram_code', fields: ['code'])]
class MtsDiagram extends MtsCatalogItem
{
    #[\Override]
    public function getKind(): MtsCatalogKind
    {
        return MtsCatalogKind::Diagram;
    }
}
