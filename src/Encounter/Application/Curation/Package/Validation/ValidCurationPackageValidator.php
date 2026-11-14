<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package\Validation;

use App\Encounter\Application\Curation\Package\CatalogEntryData;
use App\Encounter\Application\Curation\Package\CurationChecksum;
use App\Encounter\Application\Curation\Package\CurationPackage;
use App\Encounter\Application\Curation\Package\CurationPackageFormat;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Checks the rules of a whole package:
 *
 * - counts per section and the checksum;
 * - codes unique per kind across entries and aliases (ADR 032), area codes unique;
 * - alias targets, assignments and area rules name entries of the package (aliases point to an entry, never to
 *   another alias, so resolution takes one step);
 * - a PZC belongs to at most one area (ADR 033).
 *
 * @psalm-suppress UnusedClass Resolved by ValidCurationPackage::validatedBy().
 */
final class ValidCurationPackageValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CurationChecksum $checksum,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCurationPackage) {
            throw new UnexpectedTypeException($constraint, ValidCurationPackage::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof CurationPackage) {
            throw new UnexpectedValueException($value, CurationPackage::class);
        }

        $this->checkCounts($value);
        $this->checkChecksum($value);

        $diagrams = $this->entryCodes('diagrams', $value->diagrams);
        $indicators = $this->entryCodes('indicators', $value->indicators);
        $this->checkAliases($value, [MtsCatalogKind::Diagram->value => $diagrams, MtsCatalogKind::Indicator->value => $indicators]);
        $this->checkAssignments($value, $diagrams, $indicators);
        $this->checkAreas($value, $diagrams);
    }

    private function checkCounts(CurationPackage $package): void
    {
        $actual = $package->counts();
        foreach (CurationPackageFormat::SECTIONS as $section) {
            if (($package->meta->counts[$section] ?? null) !== $actual[$section]) {
                $this->violation('meta.counts', \sprintf('Count for "%s" is %s, but the section has %d entries.', $section, (string) ($package->meta->counts[$section] ?? 'missing'), $actual[$section]));
            }
        }

        foreach (array_keys($package->meta->counts) as $key) {
            if (!\in_array($key, CurationPackageFormat::SECTIONS, true)) {
                $this->violation('meta.counts', \sprintf('Unknown section "%s" in meta.counts.', $key));
            }
        }
    }

    private function checkChecksum(CurationPackage $package): void
    {
        $checksum = $package->meta->checksum;
        if (CurationPackageFormat::CHECKSUM_ALGORITHM !== $checksum->algorithm) {
            $this->violation('meta.checksum.algorithm', \sprintf('Expected the checksum algorithm "%s".', CurationPackageFormat::CHECKSUM_ALGORITHM));

            return;
        }

        if (!hash_equals($this->checksum->compute($package), $checksum->value)) {
            $this->violation('meta.checksum.value', 'Checksum does not match the content. The file was changed or truncated after export.');
        }
    }

    /**
     * @param list<CatalogEntryData> $entries
     *
     * @return array<string, int> index by code
     */
    private function entryCodes(string $section, array $entries): array
    {
        $codes = [];
        foreach ($entries as $index => $entry) {
            if (isset($codes[$entry->code])) {
                $this->violation(\sprintf('%s[%d].code', $section, $index), \sprintf('Code "%s" is already used by %s[%d].', $entry->code, $section, $codes[$entry->code]));
                continue;
            }

            $codes[$entry->code] = $index;
        }

        return $codes;
    }

    /**
     * @param array<string, array<string, int>> $entries entry codes by kind
     */
    private function checkAliases(CurationPackage $package, array $entries): void
    {
        $seen = [];
        foreach ($package->aliases as $index => $alias) {
            $path = \sprintf('aliases[%d]', $index);
            $codes = $entries[$alias->kind] ?? [];

            if (isset($codes[$alias->code])) {
                $this->violation($path.'.code', \sprintf('Code "%s" is also the code of an entry of kind %s.', $alias->code, $alias->kind));
            }

            if (isset($seen[$alias->kind][$alias->code])) {
                $this->violation($path.'.code', \sprintf('Alias code "%s" is already used by aliases[%d].', $alias->code, $seen[$alias->kind][$alias->code]));
            } else {
                $seen[$alias->kind][$alias->code] = $index;
            }

            if (!isset($codes[$alias->targetCode])) {
                $this->violation($path.'.target_code', \sprintf('The target "%s" is not an entry of kind %s in this package.', $alias->targetCode, $alias->kind));
            }
        }
    }

    /**
     * @param array<string, int> $diagrams
     * @param array<string, int> $indicators
     */
    private function checkAssignments(CurationPackage $package, array $diagrams, array $indicators): void
    {
        $seen = [];
        foreach ($package->assignments as $index => $assignment) {
            $path = \sprintf('assignments[%d]', $index);
            if (!isset($diagrams[$assignment->diagram])) {
                $this->violation($path.'.diagram', \sprintf('The diagram "%s" is not in this package.', $assignment->diagram));
            }

            if (!isset($indicators[$assignment->indicator])) {
                $this->violation($path.'.indicator', \sprintf('The indicator "%s" is not in this package.', $assignment->indicator));
            }

            $key = $assignment->diagram.'|'.$assignment->indicator;
            if (isset($seen[$key])) {
                $this->violation($path, \sprintf('Same assignment as assignments[%d].', $seen[$key]));
            } else {
                $seen[$key] = $index;
            }
        }
    }

    /**
     * @param array<string, int> $diagrams
     */
    private function checkAreas(CurationPackage $package, array $diagrams): void
    {
        $codes = [];
        $owners = [];
        foreach ($package->problemAreas as $index => $area) {
            $path = \sprintf('problem_areas[%d]', $index);
            if (isset($codes[$area->code])) {
                $this->violation($path.'.code', \sprintf('Area code "%s" is already used by problem_areas[%d].', $area->code, $codes[$area->code]));
            } else {
                $codes[$area->code] = $index;
            }

            foreach ($area->pzc as $position => $pzc) {
                if (isset($owners[$pzc]) && $owners[$pzc] !== $index) {
                    $this->violation(\sprintf('%s.pzc[%d]', $path, $position), \sprintf('PZC %03d also belongs to problem_areas[%d]; a PZC belongs to at most one area.', $pzc, $owners[$pzc]));
                } else {
                    $owners[$pzc] = $index;
                }
            }

            $rules = [];
            foreach ($area->diagrams as $position => $rule) {
                $rulePath = \sprintf('%s.diagrams[%d]', $path, $position);
                if (!isset($diagrams[$rule->code])) {
                    $this->violation($rulePath.'.code', \sprintf('The diagram "%s" is not in this package.', $rule->code));
                }

                if (isset($rules[$rule->code])) {
                    $this->violation($rulePath.'.code', \sprintf('The diagram "%s" already has a rule in this area.', $rule->code));
                }

                $rules[$rule->code] = true;
            }
        }
    }

    private function violation(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
