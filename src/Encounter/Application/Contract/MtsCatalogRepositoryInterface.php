<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogKind;

/**
 * Lookups for the maintenance of the MTS catalog (MtsCatalogCuration, ADR 032).
 */
interface MtsCatalogRepositoryInterface
{
    public function findEntry(MtsCatalogKind $kind, string $code): ?MtsCatalogItem;

    public function findAlias(MtsCatalogKind $kind, string $code): ?MtsAlias;

    public function countAliasesTargeting(MtsCatalogKind $kind, string $code): int;

    public function countAssignments(MtsCatalogItem $entry): int;

    public function findAssignment(MtsDiagram $diagram, MtsIndicator $indicator): ?MtsDiagramIndicator;

    /**
     * Active entries (curated or provisional) of one kind, ordered by code.
     *
     * @return list<MtsCatalogItem>
     */
    public function activeEntries(MtsCatalogKind $kind): array;
}
