<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\DataFixtures;

use App\DataFixtures\Purger\FixturePurgeParticipantInterface;
use Cois\Plugin\EdVisit\Application\Plausibility\PlausibilityCuration;

/**
 * What a fixture load keeps of the ED visit data (#1300): the MTS catalog, seeded by its migration and maintained in
 * the app (ADR 032), and the curated problem areas of the plausibility table (ADR 033, #1219). Their snapshots and
 * cells are derived from them and from the purged data, so they are purged and the seed draft is rebuilt afterwards;
 * nothing is activated.
 */
final readonly class EdVisitFixturePurge implements FixturePurgeParticipantInterface
{
    private const array MTS_CATALOG_TABLES = [
        'encounter_mts_diagram',
        'encounter_mts_indicator',
        'encounter_mts_alias',
        'encounter_mts_diagram_indicator',
    ];

    private const array PLAUSIBILITY_AREA_TABLES = [
        'encounter_plausibility_area',
        'encounter_plausibility_area_code',
        'encounter_plausibility_area_diagram',
    ];

    public function __construct(
        private PlausibilityCuration $plausibilityCuration,
    ) {
    }

    #[\Override]
    public function keptTables(): array
    {
        return [...self::MTS_CATALOG_TABLES, ...self::PLAUSIBILITY_AREA_TABLES];
    }

    #[\Override]
    public function afterPurge(): void
    {
        $this->plausibilityCuration->rebuildSeedDraft();
    }
}
