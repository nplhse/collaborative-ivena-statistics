<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Columns a schema adds to the Explore allocation list (ADR 037, #1203), for example the initial triage of the ED
 * visit. Users pick them in the column picker; their cells are loaded after the page has rendered, one request for
 * every visible column of a page. The core has checked `VIEW_CASES` on each encounter's hospital before it asks, so
 * a provider checks no access itself. Keep it to one query per call, whatever the number of encounters and columns.
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterListColumnProviderInterface
{
    public const string TAG = 'encounter.list_column';

    /**
     * @return list<EncounterListColumn>
     */
    public function columns(): array;

    /**
     * @param list<int>    $encounterIds
     * @param list<string> $columns      keys of this provider's columns, at least one
     *
     * @return array<string, array<int, EncounterListCell>> column key => encounter id => cell; none for an encounter
     *                                                      without a value
     */
    public function cellsForEncounters(array $encounterIds, array $columns): array;
}
