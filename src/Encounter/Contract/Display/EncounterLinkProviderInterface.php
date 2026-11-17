<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Links to the record page of encounters, for pages of the core that list encounters (the assignment conflicts,
 * #1300). A schema returns a link for the encounters it has a record page for; the first link wins. The core
 * has checked the user's access to the encounters' hospital before it asks; the linked page checks again.
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterLinkProviderInterface
{
    public const string TAG = 'encounter.link';

    /**
     * @param list<int> $encounterIds
     *
     * @return array<int, string> URL by encounter id, only for the encounters the schema has a page for
     */
    public function linksForEncounters(array $encounterIds): array;
}
