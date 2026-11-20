<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\AllocationList;

use App\Encounter\Contract\Display\EncounterListDetail;
use App\Encounter\Contract\Display\EncounterListDetailProviderInterface;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;

/**
 * Initial triage of the ED visit in the column "Encounter" of the Explore allocation list (#1203): MTS category
 * as a colour dot, presentation diagram and indicator. One query for all encounters of a page; visits without any
 * triage value get no detail.
 *
 * @psalm-suppress UnusedClass Tagged via EncounterListDetailProviderInterface.
 */
final readonly class EdVisitListDetailProvider implements EncounterListDetailProviderInterface
{
    public function __construct(
        private VisitListReaderInterface $visits,
    ) {
    }

    #[\Override]
    public function detailsForEncounters(array $encounterIds): array
    {
        $details = [];
        foreach ($this->visits->triages($encounterIds) as $encounterId => $triage) {
            if (null === $triage['category'] && null === $triage['diagram'] && null === $triage['indicator']) {
                continue;
            }
            $details[$encounterId] = new EncounterListDetail('@EdVisit/allocation_list/_triage.html.twig', ['triage' => $triage]);
        }

        return $details;
    }
}
