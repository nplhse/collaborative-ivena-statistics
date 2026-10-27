<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistrictPolygons;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistricts;
use App\DataFixtures\Reference\SyntheticReferenceCatalogBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SyntheticReferenceCatalogBuilderTest extends KernelTestCase
{
    private const string CURATED_HASHES = __DIR__.'/../../Support/ReferenceCatalog/curated-label-hashes.txt';

    public function testBuildsAValidCompletePackage(): void
    {
        $package = $this->builder()->build();

        /** @var ReferenceCatalogPackageReader $reader */
        $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);
        $result = $reader->readJson($this->writer()->encode($package));

        self::assertSame([], $result->violations);
        self::assertTrue($package->meta->complete);
        self::assertSame('synthetic', $package->meta->sourceEnvironment);
        self::assertSame(208, $package->meta->counts['indication-normalized']);
        self::assertSame(SyntheticReferenceCatalogBuilder::DEFAULT_HOSPITALS, $package->meta->counts['hospital']);
        self::assertGreaterThan(208, $package->meta->counts['indication-raw'], 'variants and unmapped labels on top of the official wording');
        self::assertContains(null, array_map(static fn (\App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry $raw): ?string => $raw->target, $package->indicationsRaw), 'unmapped raw labels');
    }

    public function testTheSameSeedGivesTheSameBytes(): void
    {
        $first = $this->writer()->encode($this->builder()->build(7, 30));

        self::assertSame($first, $this->writer()->encode($this->builder()->build(7, 30)));
        self::assertNotSame($first, $this->writer()->encode($this->builder()->build(8, 30)));
    }

    public function testIdsAreStableAcrossSeeds(): void
    {
        $first = $this->builder()->build(1, 24);
        $second = $this->builder()->build(2, 24);

        self::assertSame('9fdd440e-573c-54db-a808-6a9e96a4d173', $first->states[0]->id, 'the id namespace must never change');
        self::assertSame($first->states[0]->id, $second->states[0]->id);
        $ids = static fn (array $entries): array => array_map(static fn (NormalizedIndicationEntry $entry): string => $entry->id, $entries);
        self::assertSame($ids($first->indicationsNormalized), $ids($second->indicationsNormalized));
    }

    public function testEveryDispatchAreaHasAHospitalInsideItsDistrict(): void
    {
        $package = $this->builder()->build();
        $areaNames = [];
        foreach ($package->dispatchAreas as $area) {
            $areaNames[$area->id] = $area->name;
        }

        $covered = [];
        foreach ($package->hospitals as $hospital) {
            $name = $areaNames[$hospital->area];
            $covered[$name] = true;
            self::assertNotNull($hospital->coordinates);
            self::assertTrue(HesseDistrictPolygons::contains(HesseDistricts::DISTRICTS[$name]['geo_key'], $hospital->coordinates->lat, $hospital->coordinates->lng), $hospital->name);
        }

        self::assertCount(24, $covered);
    }

    public function testNoLabelOfTheBuiltCatalogIsACuratedLabel(): void
    {
        $curated = array_flip(array_values(array_filter(
            array_map(trim(...), (array) file(self::CURATED_HASHES)),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        )));

        foreach ([SyntheticReferenceCatalogBuilder::DEFAULT_SEED, 1, 2] as $seed) {
            $package = $this->builder()->build($seed);
            $labels = [];
            foreach ([...$package->indicationsNormalized, ...$package->indicationsRaw, ...$package->indicationGroups] as $entry) {
                $labels[] = $entry->name;
            }
            foreach ([...$package->departments, ...$package->specialities] as $entry) {
                self::assertInstanceOf(AliasedEntry::class, $entry);
                $labels[] = $entry->name;
                foreach ($entry->aliases as $alias) {
                    $labels[] = $alias->name;
                }
            }

            foreach ($labels as $label) {
                $hash = hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label))));
                self::assertArrayNotHasKey($hash, $curated, sprintf('seed %d: "%s" is a curated label', $seed, $label));
            }
        }
    }

    private function builder(): SyntheticReferenceCatalogBuilder
    {
        return new SyntheticReferenceCatalogBuilder($this->writer());
    }

    private function writer(): ReferenceCatalogPackageWriter
    {
        self::bootKernel();

        /** @var ReferenceCatalogPackageWriter $writer */
        $writer = self::getContainer()->get(ReferenceCatalogPackageWriter::class);

        return $writer;
    }
}
