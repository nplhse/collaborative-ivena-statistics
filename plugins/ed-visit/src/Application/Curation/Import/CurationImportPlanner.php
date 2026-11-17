<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Import;

use Cois\Plugin\EdVisit\Application\Contract\CurationStateReaderInterface;
use Cois\Plugin\EdVisit\Application\Contract\PlausibilityRepositoryInterface;
use Cois\Plugin\EdVisit\Application\Curation\CurationState;
use Cois\Plugin\EdVisit\Application\Curation\Package\AliasData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CatalogEntryData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackage;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilitySnapshot;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;

/**
 * Compares a valid package with this instance by business key (#1198). The import only adds: a row the target lacks
 * is new, an identical row is unchanged, any difference is a conflict. Rows only the target has are kept and not
 * reported.
 *
 * Entry (kind, code), first matching rule:
 * 1. curated entry with the same German name, English name and source: unchanged;
 * 2. curated entry with other values: conflict;
 * 3. provisional entry: conflict, curate it in the back office first;
 * 4. merged entry or alias with this code: conflict;
 * 5. unknown code: new.
 *
 * Alias (kind, code), first matching rule:
 * 1. alias with the same code, label and target: unchanged, also when the merged entry of that merge has the code;
 * 2. alias with another label or target: conflict;
 * 3. provisional entry: conflict, merge it in the back office into the alias target first;
 * 4. curated entry, or merged entry without a matching alias: conflict;
 * 5. unknown code: new.
 *
 * Problem areas are collections: a missing area is new; for an existing one its names must match, missing PZC codes
 * and rules are added, a PZC of another area and a rule with other points are conflicts. Nothing is moved or removed.
 */
final readonly class CurationImportPlanner
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private CurationStateReaderInterface $reader,
        private PlausibilityRepositoryInterface $plausibilityRepository,
    ) {
    }

    public function plan(CurationPackage $package): CurationImportPlan
    {
        $state = $this->reader->state();
        $counts = array_fill_keys(CurationImportPlan::SECTIONS, ['new' => 0, 'unchanged' => 0, 'conflict' => 0]);
        $conflicts = [];

        $newEntries = [];
        $sections = [
            'diagrams' => [MtsCatalogKind::Diagram, $package->diagrams],
            'indicators' => [MtsCatalogKind::Indicator, $package->indicators],
        ];
        foreach ($sections as $section => [$kind, $entries]) {
            $newEntries[$section] = [];
            foreach ($entries as $index => $entry) {
                $isNew = null === $state->entry($kind->value, $entry->code) && null === $state->alias($kind->value, $entry->code);
                if ($isNew) {
                    $newEntries[$section][] = $entry;
                }

                $this->count($counts, $section, $this->entryConflict($state, $kind, $entry, \sprintf('%s[%d]', $section, $index)), $conflicts, $isNew);
            }
        }

        $newAliases = [];
        foreach ($package->aliases as $index => $alias) {
            $isNew = null === $state->alias($alias->kind, $alias->code) && null === $state->entry($alias->kind, $alias->code);
            if ($isNew) {
                $newAliases[] = $alias;
            }

            $this->count($counts, 'aliases', $this->aliasConflict($state, $alias, \sprintf('aliases[%d]', $index)), $conflicts, $isNew);
        }

        $newAssignments = [];
        foreach ($package->assignments as $assignment) {
            $isNew = !$state->hasAssignment($assignment->diagram, $assignment->indicator);
            if ($isNew) {
                $newAssignments[] = $assignment;
            }

            $this->count($counts, 'assignments', null, $conflicts, $isNew);
        }

        $newAreas = [];
        $extensions = [];
        foreach ($package->problemAreas as $index => $area) {
            $path = \sprintf('problem_areas[%d]', $index);
            $target = $state->areas[$area->code] ?? null;

            if (null === $target) {
                $newAreas[] = $area;
                $this->count($counts, 'problem_areas', null, $conflicts, true);
            } else {
                $conflict = $area->germanName === $target['german_name'] && $area->englishName === $target['english_name']
                    ? null
                    : new CurationConflict(
                        $path,
                        'Another problem area has this code on the target.',
                        $this->names($area->germanName, $area->englishName),
                        $this->names($target['german_name'], $target['english_name']),
                        'Correct the names in the back office (plausibility table) or in the source, then import again.',
                    );
                $this->count($counts, 'problem_areas', $conflict, $conflicts, false);
            }

            $addedPzc = [];
            foreach ($area->pzc as $position => $pzc) {
                $owner = $state->areaOfPzc($pzc);
                if ($owner === $area->code) {
                    $this->count($counts, 'area_pzc', null, $conflicts, false);
                    continue;
                }

                if (null !== $owner) {
                    $this->count($counts, 'area_pzc', new CurationConflict(
                        \sprintf('%s.pzc[%d]', $path, $position),
                        \sprintf('PZC %03d belongs to another problem area on the target; the import does not move PZC codes.', $pzc),
                        $area->code,
                        $owner,
                        'Move the PZC in the back office (plausibility table) if it belongs to this area, then import again.',
                    ), $conflicts, false);
                    continue;
                }

                $addedPzc[] = $pzc;
                $this->count($counts, 'area_pzc', null, $conflicts, true);
            }

            $addedRules = [];
            foreach ($area->diagrams as $position => $rule) {
                $points = null === $target ? null : ($target['rules'][$rule->code] ?? null);
                if (null === $points) {
                    $addedRules[] = $rule;
                    $this->count($counts, 'area_rules', null, $conflicts, true);
                    continue;
                }

                $this->count($counts, 'area_rules', $points === $rule->points ? null : new CurationConflict(
                    \sprintf('%s.diagrams[%d]', $path, $position),
                    \sprintf('The rule for diagram "%s" has other points on the target.', $rule->code),
                    \sprintf('%s: %d', $rule->code, $rule->points),
                    \sprintf('%s: %d', $rule->code, $points),
                    'Set the points in the back office (plausibility table) or in the source, then import again.',
                ), $conflicts, false);
            }

            if (null !== $target && ([] !== $addedPzc || [] !== $addedRules)) {
                $extensions[] = new AreaExtension($area->code, $addedPzc, $addedRules);
            }
        }

        $seedDraft = $this->plausibilityRepository->seedDraft();

        return new CurationImportPlan(
            $counts,
            $newEntries['diagrams'],
            $newEntries['indicators'],
            $newAliases,
            $newAssignments,
            $newAreas,
            $extensions,
            $conflicts,
            $seedDraft instanceof PlausibilitySnapshot ? $seedDraft->getId() : null,
        );
    }

    private function entryConflict(CurationState $state, MtsCatalogKind $kind, CatalogEntryData $entry, string $path): ?CurationConflict
    {
        $package = $this->entryValues($entry->germanName, $entry->englishName, $entry->source);
        $existing = $state->entry($kind->value, $entry->code);

        if (null !== $existing) {
            return match ($existing['status']) {
                MtsCatalogStatus::Curated => $entry->germanName === $existing['german_name'] && $entry->englishName === $existing['english_name'] && $entry->source === $existing['source']
                    ? null
                    : new CurationConflict(
                        $path,
                        \sprintf('Another curated %s has this code on the target.', $kind->value),
                        $package,
                        $this->entryValues($existing['german_name'], $existing['english_name'], $existing['source']),
                        'Correct the entry in the back office or in the source so both are the same, then import again.',
                    ),
                MtsCatalogStatus::Provisional => new CurationConflict(
                    $path,
                    \sprintf('The code is a provisional %s on the target.', $kind->value),
                    $package,
                    'provisional: '.$this->entryValues($existing['german_name'], $existing['english_name'], $existing['source']),
                    \sprintf(
                        'Curate the entry in the back office: German name "%s", English name %s, source %s, status curated; then import again.',
                        $entry->germanName,
                        null === $entry->englishName ? 'empty' : \sprintf('"%s"', $entry->englishName),
                        $entry->source,
                    ),
                ),
                MtsCatalogStatus::Merged => new CurationConflict(
                    $path,
                    \sprintf('The code is a merged %s on the target.', $kind->value),
                    $package,
                    'merged into '.($state->alias($kind->value, $entry->code)['target_code'] ?? '?'),
                    'Undo the merge in the back office and curate the entry, or remove the entry from the source; then import again.',
                ),
            };
        }

        $alias = $state->alias($kind->value, $entry->code);
        if (null !== $alias) {
            return new CurationConflict(
                $path,
                \sprintf('The code is an alias of a %s on the target.', $kind->value),
                $package,
                \sprintf('alias "%s" → %s', $alias['label'], $alias['target_code']),
                'Remove the alias in the back office or use the target entry in the source, then import again.',
            );
        }

        return null;
    }

    private function aliasConflict(CurationState $state, AliasData $alias, string $path): ?CurationConflict
    {
        $package = $this->aliasValue($alias->label, $alias->targetCode);
        $existing = $state->alias($alias->kind, $alias->code);

        if (null !== $existing) {
            return $existing['label'] === $alias->label && $existing['target_code'] === $alias->targetCode
                ? null
                : new CurationConflict(
                    $path,
                    'An alias with this code has another label or target on the target.',
                    $package,
                    $this->aliasValue($existing['label'], $existing['target_code']),
                    'Correct the alias in the back office or in the source so both are the same, then import again.',
                );
        }

        $entry = $state->entry($alias->kind, $alias->code);
        if (null === $entry) {
            return null;
        }

        $target = \sprintf('%s: %s', $entry['status']->value, $this->entryValues($entry['german_name'], $entry['english_name'], $entry['source']));

        return match ($entry['status']) {
            MtsCatalogStatus::Provisional => new CurationConflict(
                $path,
                \sprintf('The code is a provisional %s on the target.', $alias->kind),
                $package,
                $target,
                \sprintf('Set its German name to "%s" and merge it in the back office into "%s"; then import again.', $alias->label, $alias->targetCode),
            ),
            MtsCatalogStatus::Curated => new CurationConflict(
                $path,
                \sprintf('The code is a curated %s on the target.', $alias->kind),
                $package,
                $target,
                'Decide in the back office or in the source whether the code is an entry or an alias, then import again.',
            ),
            MtsCatalogStatus::Merged => new CurationConflict(
                $path,
                \sprintf('The code is a merged %s on the target without a matching alias.', $alias->kind),
                $package,
                $target,
                'Undo the merge in the back office and merge the entry into the alias target, then import again.',
            ),
        };
    }

    /**
     * @param array<string, array{new: int, unchanged: int, conflict: int}> $counts
     * @param list<CurationConflict>                                        $conflicts
     */
    private function count(array &$counts, string $section, ?CurationConflict $conflict, array &$conflicts, bool $isNew): void
    {
        $count = $counts[$section];
        if ($conflict instanceof CurationConflict) {
            $conflicts[] = $conflict;
            $counts[$section] = ['new' => $count['new'], 'unchanged' => $count['unchanged'], 'conflict' => $count['conflict'] + 1];

            return;
        }

        $counts[$section] = $isNew
            ? ['new' => $count['new'] + 1, 'unchanged' => $count['unchanged'], 'conflict' => $count['conflict']]
            : ['new' => $count['new'], 'unchanged' => $count['unchanged'] + 1, 'conflict' => $count['conflict']];
    }

    private function entryValues(string $germanName, ?string $englishName, string $source): string
    {
        return \sprintf('%s / %s', $this->names($germanName, $englishName), $source);
    }

    private function names(string $germanName, ?string $englishName): string
    {
        return \sprintf('"%s" / %s', $germanName, null === $englishName ? '–' : \sprintf('"%s"', $englishName));
    }

    private function aliasValue(string $label, string $targetCode): string
    {
        return \sprintf('"%s" → %s', $label, $targetCode);
    }
}
