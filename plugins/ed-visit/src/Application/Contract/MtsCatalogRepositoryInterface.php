<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Domain\Entity\MtsAlias;
use Cois\Plugin\EdVisit\Domain\Entity\MtsCatalogItem;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagram;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagramIndicator;
use Cois\Plugin\EdVisit\Domain\Entity\MtsIndicator;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;

/**
 * Lookups for the maintenance of the MTS catalog (MtsCatalogCuration, ADR 032).
 */
interface MtsCatalogRepositoryInterface
{
    public function findEntry(MtsCatalogKind $kind, string $code): ?MtsCatalogItem;

    public function findAlias(MtsCatalogKind $kind, string $code): ?MtsAlias;

    public function countAliasesTargeting(MtsCatalogKind $kind, string $code): int;

    public function countAssignments(MtsCatalogItem $entry): int;

    /**
     * Curated rules of the plausibility table (problem area × diagram) that name the entry; 0 for an indicator.
     */
    public function countPlausibilityRules(MtsCatalogItem $entry): int;

    public function findAssignment(MtsDiagram $diagram, MtsIndicator $indicator): ?MtsDiagramIndicator;

    /**
     * Active entries (curated or provisional) of one kind, ordered by code.
     *
     * @return list<MtsCatalogItem>
     */
    public function activeEntries(MtsCatalogKind $kind): array;
}
