<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Sections of a schema's records of one encounter (ADR 037), shown in the hospital course card of the
 * allocation case file. The core has checked `VIEW_CASES` on the encounter's hospital before it asks, so a
 * provider checks no access itself. Keep it to one query per call: the case file has a query budget.
 */
#[AutoconfigureTag(self::TAG)]
interface RecordSectionProviderInterface
{
    public const string TAG = 'encounter.record_section';

    /**
     * @return list<RecordSection> none when the schema has no record of the encounter
     */
    public function sectionsForEncounter(int $encounterId): array;
}
