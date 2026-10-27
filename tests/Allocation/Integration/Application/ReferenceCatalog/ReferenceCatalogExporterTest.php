<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogExporter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\IndicationKey;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReferenceCatalogExporterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testExportsIdsReferencesAndCuratedFields(): void
    {
        self::bootKernel();
        $fixture = $this->buildCatalog();

        $package = $this->exporter()->export(ReferenceCatalogType::importOrder(), 'test export');

        self::assertTrue($package->meta->complete);
        self::assertSame(ReferenceCatalogType::values(), $package->meta->types);
        self::assertSame('2026-10-27T10:00:00+01:00', $package->meta->exportedAt);
        self::assertSame('test', $package->meta->sourceEnvironment);
        self::assertSame('test export', $package->meta->notes);
        self::assertSame(1, $package->meta->counts['hospital']);
        self::assertSame(2, $package->meta->counts['indication-raw']);

        $area = $package->dispatchAreas[0];
        self::assertSame($fixture['state'], $area->state);

        $department = $package->departments[0];
        self::assertSame('Allgemeine Innere Medizin', $department->name);
        self::assertCount(1, $department->aliases);
        self::assertSame('faulty_catalog', $department->aliases[0]->classification);
        self::assertSame('typo in source', $department->aliases[0]->note);
        self::assertSame('2024-01-01', $department->aliases[0]->validFrom);

        $normalized = array_column(array_map(static fn (\App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry $entry): array => ['id' => $entry->id, 'name' => $entry->name, 'note' => $entry->note], $package->indicationsNormalized), null, 'name');
        self::assertSame('PZC 143', $normalized['vaECMO Abholung']['note']);

        $raw = array_column(array_map(static fn (\App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry $entry): array => ['name' => $entry->name, 'entry' => $entry], $package->indicationsRaw), 'entry', 'name');
        $mapped = $raw['ECMO Abholung'];
        self::assertSame($fixture['ecmo'], $mapped->target, 'a curated mapping between different labels is kept');
        self::assertSame('matched', $mapped->review->status);
        self::assertSame('checked by hand', $mapped->review->comment);
        self::assertSame('2026-03-01T08:30:00+01:00', $mapped->review->reviewedAt);
        self::assertSame('2026-02-01T08:30:00+01:00', $mapped->firstMatchedAt);
        self::assertNull($raw['Unbekannte Lage']->target);
        self::assertSame('unreviewed', $raw['Unbekannte Lage']->review->status);

        $group = $package->indicationGroups[0];
        self::assertSame([$fixture['ecmo']], $group->members, 'only the exact member, not every row with the same code');
        self::assertSame('ECMO transports', $group->description);
        self::assertSame(3, $group->sortOrder);

        $hospital = $package->hospitals[0];
        self::assertSame($fixture['hospital'], $hospital->id);
        self::assertSame($fixture['state'], $hospital->state);
        self::assertSame($fixture['area'], $hospital->area);
        self::assertSame('Full', $hospital->tier);
        self::assertNotNull($hospital->coordinates);
        self::assertSame(50.1, $hospital->coordinates->lat);
        self::assertSame(8.7, $hospital->coordinates->lng);

        $this->assertReadsBack($package);
    }

    public function testSameDatabaseGivesTheSameBytes(): void
    {
        self::bootKernel();
        $this->buildCatalog();

        $writer = $this->writer();
        $first = $writer->encode($this->exporter()->export(ReferenceCatalogType::importOrder()));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $second = $writer->encode($this->exporter()->export(ReferenceCatalogType::importOrder()));

        self::assertSame($first, $second);
        self::assertStringEndsWith("}\n", $first);
        self::assertStringContainsString('"name": "Allgemeine Innere Medizin"', $first, 'pretty-printed with unescaped UTF-8');
    }

    public function testPartialExportListsOnlyTheChosenTypes(): void
    {
        self::bootKernel();
        $this->buildCatalog();

        $package = $this->exporter()->export([ReferenceCatalogType::Hospital, ReferenceCatalogType::State]);

        self::assertFalse($package->meta->complete);
        self::assertSame(['state', 'hospital'], $package->meta->types);
        self::assertSame(['state' => 1, 'hospital' => 1], $package->meta->counts);
        self::assertSame([], $package->departments);
        self::assertSame([], $package->dispatchAreas);

        $this->assertReadsBack($package);
    }

    /**
     * @return array{state: string, area: string, ecmo: string, hospital: string}
     */
    private function buildCatalog(): array
    {
        self::mockTime(new \DateTimeImmutable('2026-10-27 10:00:00', new \DateTimeZone('Europe/Berlin')));

        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);

        $department = DepartmentFactory::createOne(['name' => 'Allgemeine Innere Medizin']);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new DepartmentAlias($department, 'Allgemein Innere Medizin', 'faulty_catalog', 'local-catalog', 'typo in source', '2024-01-01'));

        $ecmo = IndicationNormalizedFactory::createOne(['code' => 143, 'name' => 'vaECMO Abholung', 'note' => 'PZC 143']);
        IndicationNormalizedFactory::createOne(['code' => 143, 'name' => 'ECMO Sekundärtransport']);

        IndicationRawFactory::createOne([
            'code' => 143,
            'name' => 'ECMO Abholung',
            'hash' => IndicationKey::hashFrom('143', 'ECMO Abholung'),
            'target' => $ecmo,
            'normalized' => $ecmo,
            'reviewStatus' => IndicationRawReviewStatus::Matched,
            'reviewComment' => 'checked by hand',
            'reviewedAt' => new \DateTimeImmutable('2026-03-01T08:30:00+01:00'),
            'firstMatchedAt' => new \DateTimeImmutable('2026-02-01T08:30:00+01:00'),
        ]);
        IndicationRawFactory::createOne([
            'code' => 999,
            'name' => 'Unbekannte Lage',
            'hash' => IndicationKey::hashFrom('999', 'Unbekannte Lage'),
        ]);

        $group = IndicationGroupFactory::createOne(['name' => 'ECMO', 'description' => 'ECMO transports', 'category' => null, 'sortOrder' => 3]);
        $group->addIndication($ecmo);

        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Frankfurt',
            'state' => $state,
            'dispatchArea' => $area,
            'tier' => HospitalTier::FULL,
            'size' => HospitalSize::LARGE,
            'location' => HospitalLocation::URBAN,
            'beds' => 800,
            'latitude' => 50.1,
            'longitude' => 8.7,
        ]);
        $em->flush();

        return [
            'state' => $state->getPublicIdString(),
            'area' => $area->getPublicIdString(),
            'ecmo' => $ecmo->getPublicIdString(),
            'hospital' => $hospital->getPublicIdString(),
        ];
    }

    private function assertReadsBack(ReferenceCatalogPackage $package): void
    {
        /** @var ReferenceCatalogPackageReader $reader */
        $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);
        $result = $reader->readJson($this->writer()->encode($package));

        self::assertSame([], $result->violations);
    }

    private function exporter(): ReferenceCatalogExporter
    {
        /** @var ReferenceCatalogExporter $exporter */
        $exporter = self::getContainer()->get(ReferenceCatalogExporter::class);

        return $exporter;
    }

    private function writer(): ReferenceCatalogPackageWriter
    {
        /** @var ReferenceCatalogPackageWriter $writer */
        $writer = self::getContainer()->get(ReferenceCatalogPackageWriter::class);

        return $writer;
    }
}
