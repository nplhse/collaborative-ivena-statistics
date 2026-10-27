<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package\Validation;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogNaturalKey;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogAliasConflictException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameAliasSpec;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameCatalogGuard;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameEntry;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameKey;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Checks the rules of a whole package: listed types and counts, checksum, unique ids and
 * natural keys, references between sections, and aliases.
 *
 * References are checked only when the referenced type is part of the package. A partial
 * package may refer to rows that already exist in the database; the import resolves those.
 */
final class ValidReferenceCatalogPackageValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ReferenceCatalogChecksum $checksum,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidReferenceCatalogPackage) {
            throw new UnexpectedTypeException($constraint, ValidReferenceCatalogPackage::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof ReferenceCatalogPackage) {
            throw new UnexpectedValueException($value, ReferenceCatalogPackage::class);
        }

        $listed = $this->checkTypesAndCounts($value);
        $this->checkChecksum($value);
        $this->checkIdentities($value);
        $this->checkReferences($value, $listed);
        $this->checkAliases('departments', $value->departments);
        $this->checkAliases('specialities', $value->specialities);
    }

    /**
     * @return array<string, true> listed type values
     */
    private function checkTypesAndCounts(ReferenceCatalogPackage $package): array
    {
        $listed = array_fill_keys($package->meta->types, true);
        $actual = $package->counts();

        foreach (ReferenceCatalogType::importOrder() as $type) {
            $isListed = isset($listed[$type->value]);
            $section = $this->sectionOf($type);

            if (!$isListed && $actual[$type->value] > 0) {
                $this->violation($section, sprintf('Section has %d entries, but type "%s" is not listed in meta.types.', $actual[$type->value], $type->value));
            }

            if (!$isListed && \array_key_exists($type->value, $package->meta->counts)) {
                $this->violation('meta.counts', sprintf('Count given for type "%s", which is not listed in meta.types.', $type->value));
            }

            if ($isListed && ($package->meta->counts[$type->value] ?? null) !== $actual[$type->value]) {
                $this->violation('meta.counts', sprintf('Count for type "%s" is %s, but the section has %d entries.', $type->value, (string) ($package->meta->counts[$type->value] ?? 'missing'), $actual[$type->value]));
            }
        }

        foreach (array_keys($package->meta->counts) as $key) {
            if (null === ReferenceCatalogType::tryFrom($key)) {
                $this->violation('meta.counts', sprintf('Unknown type "%s" in meta.counts.', $key));
            }
        }

        $isComplete = \count($listed) === \count(ReferenceCatalogType::cases());
        if ($package->meta->complete !== $isComplete) {
            $this->violation('meta.complete', $isComplete
                ? 'All types are listed, so meta.complete must be true.'
                : 'Not all types are listed, so meta.complete must be false.');
        }

        return $listed;
    }

    private function checkChecksum(ReferenceCatalogPackage $package): void
    {
        $expected = $this->checksum->compute($package);
        if (!hash_equals($expected, $package->meta->checksum->value)) {
            $this->violation('meta.checksum.value', 'Checksum does not match the content. The file was changed or truncated after export.');
        }
    }

    private function checkIdentities(ReferenceCatalogPackage $package): void
    {
        foreach (ReferenceCatalogType::importOrder() as $type) {
            $section = $this->sectionOf($type);
            $ids = [];
            $keys = [];

            foreach ($package->entriesOf($type) as $index => $entry) {
                $idKey = strtolower($entry->id);
                if (isset($ids[$idKey])) {
                    $this->violation(sprintf('%s[%d].id', $section, $index), sprintf('Id "%s" is already used by %s[%d].', $entry->id, $section, $ids[$idKey]));
                } else {
                    $ids[$idKey] = $index;
                }

                $naturalKey = ReferenceCatalogNaturalKey::of($entry);
                if (isset($keys[$naturalKey])) {
                    $this->violation(sprintf('%s[%d]', $section, $index), sprintf('Same entry as %s[%d] (%s).', $section, $keys[$naturalKey], ReferenceCatalogNaturalKey::label($type)));
                } else {
                    $keys[$naturalKey] = $index;
                }
            }
        }
    }

    /**
     * @param array<string, true> $listed
     */
    private function checkReferences(ReferenceCatalogPackage $package, array $listed): void
    {
        $stateIds = $this->idSet($package->states);
        $areaStates = [];
        foreach ($package->dispatchAreas as $area) {
            $areaStates[strtolower($area->id)] = strtolower($area->state);
        }
        $normalizedIds = $this->idSet($package->indicationsNormalized);

        $statesListed = isset($listed[ReferenceCatalogType::State->value]);
        $areasListed = isset($listed[ReferenceCatalogType::DispatchArea->value]);
        $normalizedListed = isset($listed[ReferenceCatalogType::IndicationNormalized->value]);

        if ($statesListed) {
            foreach ($package->dispatchAreas as $index => $area) {
                if (!isset($stateIds[strtolower($area->state)])) {
                    $this->violation(sprintf('dispatchAreas[%d].state', $index), sprintf('State "%s" is not in the package.', $area->state));
                }
            }
        }

        if ($normalizedListed) {
            foreach ($package->indicationsRaw as $index => $raw) {
                if (null !== $raw->target && !isset($normalizedIds[strtolower($raw->target)])) {
                    $this->violation(sprintf('indicationsRaw[%d].target', $index), sprintf('Normalized indication "%s" is not in the package.', $raw->target));
                }
            }

            foreach ($package->indicationGroups as $index => $group) {
                foreach ($group->members as $memberIndex => $member) {
                    if (!isset($normalizedIds[strtolower($member)])) {
                        $this->violation(sprintf('indicationGroups[%d].members[%d]', $index, $memberIndex), sprintf('Normalized indication "%s" is not in the package.', $member));
                    }
                }
            }
        }

        foreach ($package->hospitals as $index => $hospital) {
            $this->checkHospitalReferences($index, $hospital, $stateIds, $areaStates, $statesListed, $areasListed);
        }
    }

    /**
     * @param array<string, true>   $stateIds
     * @param array<string, string> $areaStates
     */
    private function checkHospitalReferences(int $index, HospitalEntry $hospital, array $stateIds, array $areaStates, bool $statesListed, bool $areasListed): void
    {
        if ($statesListed && !isset($stateIds[strtolower($hospital->state)])) {
            $this->violation(sprintf('hospitals[%d].state', $index), sprintf('State "%s" is not in the package.', $hospital->state));
        }

        if (!$areasListed) {
            return;
        }

        $areaState = $areaStates[strtolower($hospital->area)] ?? null;
        if (null === $areaState) {
            $this->violation(sprintf('hospitals[%d].area', $index), sprintf('Dispatch area "%s" is not in the package.', $hospital->area));

            return;
        }

        if ($areaState !== strtolower($hospital->state)) {
            $this->violation(sprintf('hospitals[%d].area', $index), 'Dispatch area belongs to another state than the hospital.');
        }
    }

    /**
     * @param list<AliasedEntry> $entries
     */
    private function checkAliases(string $section, array $entries): void
    {
        $canonical = [];
        foreach ($entries as $entry) {
            $canonical[ReferenceNameKey::normalize($entry->name)] = $entry->name;
        }

        $aliasOwner = [];
        foreach ($entries as $index => $entry) {
            $ownKey = ReferenceNameKey::normalize($entry->name);
            foreach ($entry->aliases as $aliasIndex => $alias) {
                $path = sprintf('%s[%d].aliases[%d].name', $section, $index, $aliasIndex);
                $aliasKey = ReferenceNameKey::normalize($alias->name);

                if ($aliasKey === $ownKey) {
                    $this->violation($path, sprintf('Alias "%s" repeats its canonical name.', $alias->name));
                    continue;
                }

                if (isset($canonical[$aliasKey])) {
                    $this->violation($path, sprintf('Alias "%s" collides with canonical name "%s".', $alias->name, $canonical[$aliasKey]));
                    continue;
                }

                if (isset($aliasOwner[$aliasKey])) {
                    $this->violation($path, sprintf('Alias "%s" is already mapped to "%s".', $alias->name, $aliasOwner[$aliasKey]));
                    continue;
                }

                $aliasOwner[$aliasKey] = $entry->name;

                try {
                    new ReferenceNameCatalogGuard()->assertNoConflicts([
                        new ReferenceNameEntry($entry->name, [], [new ReferenceNameAliasSpec($alias->name, $alias->classification, $alias->source)]),
                    ]);
                } catch (ReferenceCatalogAliasConflictException $exception) {
                    $this->violation($path, $exception->getMessage());
                }
            }
        }
    }

    private function sectionOf(ReferenceCatalogType $type): string
    {
        return lcfirst(str_replace('_', '', ucwords($type->sectionKey(), '_')));
    }

    /**
     * @param list<CatalogEntry> $entries
     *
     * @return array<string, true>
     */
    private function idSet(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[strtolower($entry->id)] = true;
        }

        return $ids;
    }

    private function violation(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
