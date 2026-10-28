<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package\Validation;

use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Pattern\Package\IndicationGroupPattern;
use App\DataFixtures\Pattern\Package\PatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackageFormat;
use App\DataFixtures\Pattern\Package\PatternPackageHasher;
use App\DataFixtures\Pattern\Package\SegmentPattern;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Checks the rules of a whole pattern package: segments and groups the generator knows, keys of each
 * table (departments by generator key), shares that sum to 1, ordered percentiles, counts and checksum. Paths name the table and the key.
 */
final class ValidPatternPackageValidator extends ConstraintValidator
{
    public function __construct(
        private readonly PatternPackageHasher $hasher = new PatternPackageHasher(),
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPatternPackage) {
            throw new UnexpectedTypeException($constraint, ValidPatternPackage::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof PatternPackage) {
            throw new UnexpectedValueException($value, PatternPackage::class);
        }

        $this->checkSegments($value);
        $this->checkIndicationGroups($value);
        $this->checkCounts($value);
        $this->checkChecksum($value);
    }

    private function checkSegments(PatternPackage $package): void
    {
        foreach (PatternPackageFormat::segments() as $name) {
            if (!isset($package->segments[$name])) {
                $this->violation('segments', sprintf('Segment "%s" is missing; the generator needs %s.', $name, implode(', ', PatternPackageFormat::segments())));
            }
        }

        foreach ($package->segments as $name => $segment) {
            $path = 'segments.'.$name;
            if (!\in_array($name, PatternPackageFormat::segments(), true)) {
                $this->violation($path, sprintf('Unknown segment "%s". Known: %s.', $name, implode(', ', PatternPackageFormat::segments())));
                continue;
            }

            $this->checkSegment($path, $segment);
        }
    }

    private function checkSegment(string $path, SegmentPattern $segment): void
    {
        foreach (array_keys($segment->indications->shares) as $code) {
            if (1 !== preg_match('/^\d{3}$/', (string) $code)) {
                $this->violation(sprintf('%s.indications.shares.%s', $path, $code), 'Expected a three-digit PZC code.');
            }
        }
        foreach (array_keys($segment->transportTypes) as $type) {
            if (null === AllocationTransportType::tryFrom((string) $type)) {
                $this->violation(sprintf('%s.transport_types.%s', $path, $type), sprintf('Unknown transport type. Known: %s.', implode(', ', AllocationTransportType::getValues())));
            }
        }
        foreach (array_keys($segment->hours) as $hour) {
            if (1 !== preg_match('/^\d{1,2}$/', (string) $hour) || (int) $hour > 23) {
                $this->violation(sprintf('%s.hours.%s', $path, $hour), 'Expected an hour from 0 to 23.');
            }
        }
        $this->checkFlags($path.'.flags', $segment->flags, []);

        $this->checkSum($path.'.indications.shares', $segment->indications->shares);
        $this->checkSum($path.'.occasions', $segment->occasions);
        $this->checkSum($path.'.assignments', $segment->assignments);
        $this->checkSum($path.'.infections.shares', $segment->infections->shares);
        $this->checkSum($path.'.secondary_transports.shares', $segment->secondaryTransports->shares);
        $this->checkSum($path.'.transport_types', $segment->transportTypes);
        $this->checkSum($path.'.hours', $segment->hours);

        $previous = 0.0;
        foreach (PatternPackageFormat::PERCENTILES as $percentile) {
            $minutes = $segment->transportTimeMinutes[$percentile] ?? 0.0;
            if ($minutes < $previous) {
                $this->violation(sprintf('%s.transport_time_minutes.%s', $path, $percentile), 'Percentiles must not decrease (p25 ≤ p50 ≤ p75 ≤ p90).');
            }
            $previous = $minutes;
        }
    }

    private function checkIndicationGroups(PatternPackage $package): void
    {
        foreach ($package->indicationGroups as $prefix => $group) {
            $path = 'indication_groups.'.$prefix;
            if (1 !== preg_match('/^[1-9]\d{0,2}$/', (string) $prefix)) {
                $this->violation($path, 'Expected a PZC code prefix of one to three digits, not starting with 0.');
            }

            $this->checkIndicationGroup($path, $group);
        }

        if (!isset($package->indicationGroups[$package->fallbackGroup])) {
            $this->violation('fallback_group', sprintf('Group "%s" is not in indication_groups.', $package->fallbackGroup));
        }
    }

    private function checkIndicationGroup(string $path, IndicationGroupPattern $group): void
    {
        foreach (array_keys($group->departments) as $department) {
            if (!isset(GeneratorKeys::DEPARTMENTS[$department])) {
                $isLabel = \in_array((string) $department, array_column(GeneratorKeys::DEPARTMENTS, 'label'), true);
                $this->violation(sprintf('%s.departments.%s', $path, $department), $isLabel
                    ? sprintf('Departments are keyed by the generator key, here "%s", not by a catalog name.', GeneratorKeys::department((string) $department))
                    : 'Unknown department key. Known keys: GeneratorKeys::DEPARTMENTS (for example cardiology, stroke-unit).');
            }
        }
        foreach (array_keys($group->urgency) as $urgency) {
            if (1 !== preg_match('/^\d$/', (string) $urgency) || null === AllocationUrgency::tryFrom((int) $urgency)) {
                $this->violation(sprintf('%s.urgency.%s', $path, $urgency), 'Expected an urgency of 1, 2 or 3.');
            }
        }
        foreach (array_keys($group->ages) as $range) {
            if (1 !== preg_match('/^(\d{1,3})-(\d{1,3})$/', (string) $range, $match) || (int) $match[1] > (int) $match[2] || (int) $match[2] > 120) {
                $this->violation(sprintf('%s.ages.%s', $path, $range), 'Expected an age range "min-max" with min ≤ max ≤ 120.');
            }
        }
        $this->checkFlags($path.'.flags', $group->flags, ['requires_resus' => 'Set the resuscitation room share of a group with resus_factor.']);

        $this->checkSum($path.'.departments', $group->departments);
        $this->checkSum($path.'.urgency', $group->urgency);
        $this->checkSum($path.'.ages', $group->ages);
    }

    /**
     * @param array<array-key, float> $flags
     * @param array<string, string>   $refused flag → reason it is not allowed here
     */
    private function checkFlags(string $path, array $flags, array $refused): void
    {
        foreach (array_keys($flags) as $flag) {
            if (isset($refused[$flag])) {
                $this->violation(sprintf('%s.%s', $path, $flag), $refused[$flag]);
            } elseif (!\in_array($flag, PatternPackageFormat::FLAGS, true)) {
                $this->violation(sprintf('%s.%s', $path, $flag), sprintf('Unknown flag. Known: %s.', implode(', ', PatternPackageFormat::FLAGS)));
            }
        }
    }

    /**
     * @param array<array-key, float> $shares
     */
    private function checkSum(string $path, array $shares): void
    {
        $sum = (float) array_sum($shares);
        if (abs($sum - 1.0) > PatternPackageFormat::SUM_TOLERANCE) {
            $this->context->buildViolation(sprintf('Shares must sum to 1, they sum to %s.', round($sum, 4)))
                ->atPath($path)
                ->setInvalidValue($sum)
                ->addViolation();
        }
    }

    private function checkCounts(PatternPackage $package): void
    {
        $expected = $package->counts();
        foreach ($expected as $key => $count) {
            $given = $package->meta->counts[$key] ?? null;
            if ($given !== $count) {
                $this->violation('meta.counts.'.$key, sprintf('Count is %s, but the package has %d.', null === $given ? 'missing' : (string) $given, $count));
            }
        }
        foreach (array_keys(array_diff_key($package->meta->counts, $expected)) as $key) {
            $this->violation('meta.counts.'.$key, sprintf('Unknown count. Known: %s.', implode(', ', PatternPackageFormat::COUNTS)));
        }
    }

    private function checkChecksum(PatternPackage $package): void
    {
        if (!hash_equals($this->hasher->compute($package), $package->meta->checksum->value)) {
            $this->violation('meta.checksum.value', 'Checksum does not match the content. The file was changed or truncated after it was written.');
        }
    }

    private function violation(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
