<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Short clinical details of a schema's records for many encounters at once (ADR 037), loaded after the Explore
 * allocation list has rendered (#1203): one request per page fills the column "Encounter". The core has checked
 * `VIEW_CASES` on each encounter's hospital before it asks, so a provider checks no access itself. Keep it to one
 * query per call, whatever the number of encounters.
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterListDetailProviderInterface
{
    public const string TAG = 'encounter.list_detail';

    /**
     * @param list<int> $encounterIds
     *
     * @return array<int, EncounterListDetail> by encounter id; none for encounters without a record of the schema
     */
    public function detailsForEncounters(array $encounterIds): array;
}
