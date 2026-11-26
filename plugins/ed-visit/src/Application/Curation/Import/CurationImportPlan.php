<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Import;

use Cois\Plugin\EdVisit\Application\Curation\Package\AliasData;
use Cois\Plugin\EdVisit\Application\Curation\Package\AssignmentData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CatalogEntryData;
use Cois\Plugin\EdVisit\Application\Curation\Package\ProblemAreaData;

/**
 * What an import of a package would add, and the rows that differ from the target (#1198). The dry run shows it, the
 * import applies the same plan.
 */
final readonly class CurationImportPlan
{
    public const array SECTIONS = ['diagrams', 'indicators', 'aliases', 'assignments', 'problem_areas', 'area_pzc', 'area_rules'];

    /**
     * @param array<string, array{new: int, unchanged: int, conflict: int}> $counts         per section of SECTIONS
     * @param list<CatalogEntryData>                                        $newDiagrams
     * @param list<CatalogEntryData>                                        $newIndicators
     * @param list<AliasData>                                               $newAliases
     * @param list<AssignmentData>                                          $newAssignments
     * @param list<ProblemAreaData>                                         $newAreas
     * @param list<AreaExtension>                                           $extensions
     * @param list<CurationConflict>                                        $conflicts
     * @param int|null                                                      $seedDraftId    the seed draft a change of the areas rebuilds; null: a new one is created
     */
    public function __construct(
        public array $counts,
        public array $newDiagrams,
        public array $newIndicators,
        public array $newAliases,
        public array $newAssignments,
        public array $newAreas,
        public array $extensions,
        public array $conflicts,
        public ?int $seedDraftId,
    ) {
    }

    public function hasConflicts(): bool
    {
        return [] !== $this->conflicts;
    }

    /**
     * Number of rows the import adds: entries, aliases, assignments, areas, PZC codes and rules.
     */
    public function additionCount(): int
    {
        return array_sum(array_map(static fn (array $count): int => $count['new'], $this->counts));
    }

    public function changesAreas(): bool
    {
        return [] !== $this->newAreas || [] !== $this->extensions;
    }
}
