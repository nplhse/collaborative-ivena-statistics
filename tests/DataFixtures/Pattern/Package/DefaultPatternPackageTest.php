<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Application\IndicationGroups;
use App\DataFixtures\Pattern\Application\PatternSet;

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

        $defaults = PatternSet::defaults();
        $fromPackage = PatternSet::fromPackage($read);
        self::assertSame(DefaultDistributionPatterns::names(), $fromPackage->segmentNames());
        foreach (DefaultDistributionPatterns::names() as $name) {
            self::assertSame($defaults->get($name)->distributions, $fromPackage->get($name)->distributions, $name.': same tables in the same order');
        }
        self::assertEquals($defaults->indicationGroups(), $fromPackage->indicationGroups(), 'same indication groups, departments by label again');
        self::assertSame(array_keys(IndicationGroups::all()), array_keys($fromPackage->indicationGroups()->all()), 'same order');

        self::assertSame($written->meta->checksum->value, $read->meta->checksum->value);
        self::assertSame($read->meta->checksum->value, $fromPackage->source, 'the set records the package checksum');
        self::assertSame(PatternSet::DEFAULTS, $defaults->source);
        self::assertSame(['segments' => 3, 'indication_groups' => \count(IndicationGroups::all()), 'indications' => 29], $read->meta->counts);
        self::assertSame(DefaultPatternPackage::SOURCE, $read->meta->source);
    }

    public function testDepartmentsAreKeyedByTheGeneratorKey(): void
    {
        $package = DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));

        self::assertSame(['cardiology' => 1.0], $package->indicationGroups['332']->departments, 'STEMI');
        self::assertArrayHasKey('stroke-unit', $package->indicationGroups['42']->departments, 'stroke');
        foreach ($package->indicationGroups as $prefix => $group) {
            foreach (array_keys($group->departments) as $department) {
                self::assertArrayHasKey($department, GeneratorKeys::DEPARTMENTS, sprintf('group %s', $prefix));
            }
        }
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
}
