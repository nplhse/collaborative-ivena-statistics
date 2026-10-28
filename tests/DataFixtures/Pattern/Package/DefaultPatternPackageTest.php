<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Application\IndicationGroups;
use App\DataFixtures\Pattern\Application\PatternPackageMapper;
use App\DataFixtures\Pattern\Package\PatternCatalogNames;

/**
 * The code defaults round-trip through writer, reader and validator unchanged (#883).
 */
final class DefaultPatternPackageTest extends PatternPackageTestCase
{
    public function testTheCodeDefaultsRoundTripUnchanged(): void
    {
        $written = $this->writer()->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00')));
        $result = $this->reader()->readJson($this->writer()->encode($written));

        self::assertSame([], $result->violations);
        self::assertTrue($result->isValid());
        $read = $result->package;

        $patterns = new DefaultDistributionPatterns();
        self::assertSame(DefaultDistributionPatterns::names(), array_keys($read->segments));
        foreach (DefaultDistributionPatterns::names() as $name) {
            self::assertSame($patterns->get($name)->distributions, PatternPackageMapper::distributionsOf($read->segments[$name]), $name.': same tables in the same order');
        }

        self::assertSame(array_keys(IndicationGroups::all()), array_keys($read->indicationGroups));
        foreach (IndicationGroups::all() as $prefix => $group) {
            self::assertEquals($group, $read->indicationGroups[$prefix]->toArray(), sprintf('group %s', $prefix));
        }
        self::assertSame(IndicationGroups::FALLBACK, $read->fallbackGroup);

        self::assertSame($written->meta->checksum->value, $read->meta->checksum->value);
        self::assertSame(['segments' => 3, 'indication_groups' => \count(IndicationGroups::all()), 'indications' => 29], $read->meta->counts);
        self::assertSame(DefaultPatternPackage::SOURCE, $read->meta->source);
    }

    public function testTheSamePackageGivesTheSameBytes(): void
    {
        $build = fn (): string => $this->writer()->encode($this->writer()->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'))));

        self::assertSame($build(), $build());
        self::assertStringEndsWith("}\n", $build());
        self::assertStringContainsString('"hours": {', $build(), 'hours are an object, not a list');
    }

    public function testTheChecksumIgnoresMetaAndLayout(): void
    {
        $writer = $this->writer();
        $october = $writer->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00')));
        $november = $writer->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-11-01T00:00:00+00:00')));
        self::assertSame($october->meta->checksum->value, $november->meta->checksum->value);

        // Compact JSON and whole numbers written without fraction read as the same package.
        $compact = str_replace('"present": 1.0', '"present": 1', $this->encode($this->defaultsData(), false));
        self::assertTrue($this->reader()->readJson($compact)->isValid());
    }

    public function testTheCatalogCheckNamesMissingValues(): void
    {
        $package = $this->writer()->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00')));
        $json = $this->writer()->encode($package);

        $complete = $this->catalogOf($package->toArray());
        self::assertTrue($this->reader()->readJson($json, $complete)->isValid(), 'every value is in the catalog');

        $withoutStroke = new PatternCatalogNames(
            array_values(array_diff($complete->indicationCodes, ['322'])),
            array_values(array_diff($complete->departments, ['Schlaganfallmedizin'])),
            $complete->occasions,
            array_values(array_diff($complete->assignments, ['ZLST'])),
            $complete->infections,
            $complete->secondaryTransports,
        );
        $result = $this->reader()->readJson($json, $withoutStroke);

        $paths = array_map(static fn (\App\DataFixtures\Pattern\Package\PatternPackageViolation $violation): string => $violation->path, $result->violations);
        self::assertContains('segments.urban-full.indications.shares.322', $paths);
        self::assertContains('segments.rural-basic.assignments.ZLST', $paths);
        self::assertContains('indication_groups.42.departments.Schlaganfallmedizin', $paths);
        self::assertSame('Department "Schlaganfallmedizin" is not in the selected reference catalog.', $result->violations[array_search('indication_groups.42.departments.Schlaganfallmedizin', $paths, true)]->message);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function catalogOf(array $data): PatternCatalogNames
    {
        /** @var array<string, array<string, mixed>> $segments */
        $segments = $data['segments'];
        /** @var array<array-key, array{departments: array<array-key, float>}> $groups */
        $groups = $data['indication_groups'];
        $keys = static fn (string $table, ?string $sub = null): array => array_values(array_unique(array_map(strval(...), array_merge(...array_map(
            static fn (array $segment): array => array_keys(null === $sub ? $segment[$table] : $segment[$table][$sub]),
            array_values($segments),
        )))));

        return new PatternCatalogNames(
            $keys('indications', 'shares'),
            array_values(array_unique(array_map(strval(...), array_merge(...array_map(static fn (array $group): array => array_keys($group['departments']), array_values($groups)))))),
            $keys('occasions'),
            $keys('assignments'),
            $keys('infections', 'shares'),
            $keys('secondary_transports', 'shares'),
        );
    }
}
