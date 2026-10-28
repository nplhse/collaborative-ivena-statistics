<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Pattern\Package\IndicationGroupPattern;
use App\DataFixtures\Pattern\Package\PatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackageChecksum;
use App\DataFixtures\Pattern\Package\PatternPackageFormat;
use App\DataFixtures\Pattern\Package\PatternPackageMeta;

/**
 * The code defaults (DefaultDistributionPatterns, IndicationGroups) as a pattern package (ADR 022).
 *
 * The PHP classes stay the source of the defaults; this package is what they look like as a file, so
 * they pass the same validation as any other package. Departments are written by their generator key. Seal it with PatternPackageWriter before writing.
 */
final class DefaultPatternPackage
{
    public const string SOURCE = 'defaults';

    public static function build(\DateTimeImmutable $createdAt): PatternPackage
    {
        $patterns = new DefaultDistributionPatterns();
        $segments = [];
        foreach (DefaultDistributionPatterns::names() as $name) {
            $segments[$name] = PatternPackageMapper::segmentFromDistributions($patterns->get($name)->distributions);
        }

        $groups = [];
        foreach (IndicationGroups::all() as $prefix => $group) {
            $groups[$prefix] = new IndicationGroupPattern(
                departments: self::byKey($group['departments']),
                urgency: array_map(floatval(...), $group['urgency']),
                ages: array_map(floatval(...), $group['ages']),
                female: (float) $group['female'],
                resusFactor: (float) $group['resus_factor'],
                flags: array_map(floatval(...), $group['flags']),
            );
        }

        return new PatternPackage(
            new PatternPackageMeta(
                format: PatternPackageFormat::NAME,
                version: PatternPackageFormat::VERSION,
                createdAt: $createdAt->format(\DateTimeInterface::ATOM),
                source: self::SOURCE,
                counts: [],
                checksum: new PatternPackageChecksum(PatternPackageFormat::CHECKSUM_ALGORITHM, str_repeat('0', 64)),
                catalog: 'synthetic',
                notes: 'Code defaults: coarse, hand-written shares, not derived from real data.',
            ),
            $segments,
            $groups,
            IndicationGroups::FALLBACK,
        );
    }

    /**
     * @param array<array-key, float> $departments default label → share
     *
     * @return array<string, float> generator key → share, same order
     */
    private static function byKey(array $departments): array
    {
        $byKey = [];
        foreach ($departments as $label => $share) {
            $byKey[GeneratorKeys::department((string) $label)] = (float) $share;
        }

        return $byKey;
    }
}
