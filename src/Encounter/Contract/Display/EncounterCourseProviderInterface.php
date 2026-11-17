<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The course of an encounter in the hospital course card of the allocation case file (ADR 037, #1300), from
 * the schema whose records decide the encounter's IVENA assignment (`ed_visit` with its match review). While a
 * schema returns a course, the card offers no manual "Remove assignment"; the schema's own review decides.
 * The core has checked `VIEW_CASES` on the encounter's hospital before it asks; the first course wins.
 */
#[AutoconfigureTag(self::TAG)]
interface EncounterCourseProviderInterface
{
    public const string TAG = 'encounter.course';

    /**
     * @return EncounterCourse|null null when the schema has no record of the encounter
     */
    public function courseForEncounter(int $encounterId): ?EncounterCourse;
}
