<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\AllocationList;

use App\Encounter\Contract\Display\EncounterListCell;
use App\Encounter\Contract\Display\EncounterListColumn;
use App\Encounter\Contract\Display\EncounterListColumnProviderInterface;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;

/**
 * Initial triage of the ED visit as picker columns of the Explore allocation list (#1203): MTS category (colour badge
 * with its name), presentation diagram and indicator. One query for all encounters and columns of a page.
 *
 * @psalm-suppress UnusedClass Tagged via EncounterListColumnProviderInterface.
 */
final readonly class EdVisitListColumnProvider implements EncounterListColumnProviderInterface
{
    public const string CATEGORY = 'ed_visit_triage_category';

    public const string DIAGRAM = 'ed_visit_triage_diagram';

    public const string INDICATOR = 'ed_visit_triage_indicator';

    private const array FIELDS = [self::CATEGORY => 'category', self::DIAGRAM => 'diagram', self::INDICATOR => 'indicator'];

    private const string TEMPLATE = '@EdVisit/allocation_list/_triage_cell.html.twig';

    public function __construct(
        private VisitListReaderInterface $visits,
    ) {
    }

    #[\Override]
    public function columns(): array
    {
        return [
            new EncounterListColumn(self::CATEGORY, 'encounter.allocation_list.column.triage_category', 'encounter'),
            new EncounterListColumn(self::DIAGRAM, 'encounter.allocation_list.column.triage_diagram', 'encounter'),
            new EncounterListColumn(self::INDICATOR, 'encounter.allocation_list.column.triage_indicator', 'encounter'),
        ];
    }

    #[\Override]
    public function cellsForEncounters(array $encounterIds, array $columns): array
    {
        $cells = [];
        foreach ($this->visits->triages($encounterIds) as $encounterId => $triage) {
            foreach ($columns as $column) {
                $field = self::FIELDS[$column] ?? null;
                $value = null === $field ? null : $triage[$field];
                if (null !== $value && '' !== $value) {
                    $cells[$column][$encounterId] = new EncounterListCell(self::TEMPLATE, ['field' => $field, 'value' => $value]);
                }
            }
        }

        return $cells;
    }
}
