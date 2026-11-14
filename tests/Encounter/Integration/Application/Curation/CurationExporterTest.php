<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Curation;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Curation\CurationExporter;
use App\Encounter\Application\Curation\Package\AliasData;
use App\Encounter\Application\Curation\Package\CatalogEntryData;
use App\Encounter\Application\Curation\Package\CurationPackageWriter;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Export of the curated state (#1198) from the seeded catalog and problem areas.
 */
#[ResetDatabase]
final class CurationExporterTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private CurationExporter $exporter;

    private CurationPackageWriter $writer;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->exporter = self::getContainer()->get(CurationExporter::class);
        $this->writer = self::getContainer()->get(CurationPackageWriter::class);
    }

    public function testSameDataGivesTheSameContentAndChecksum(): void
    {
        self::mockTime('2026-11-14 10:00:00');
        $first = $this->exporter->export()->package;
        $again = $this->exporter->export()->package;
        self::mockTime('2026-11-14 11:00:00');
        $later = $this->exporter->export()->package;

        self::assertSame($this->writer->encode($first), $this->writer->encode($again), 'byte-identical with a frozen clock');
        self::assertNotSame($first->meta->exportedAt, $later->meta->exportedAt);
        self::assertSame($first->meta->checksum->value, $later->meta->checksum->value, 'the checksum covers the sections only');
        self::assertSame(['diagrams' => 40, 'indicators' => 70, 'aliases' => 0, 'assignments' => 0, 'problem_areas' => 24], $first->meta->counts);
        self::assertSame('abdomen_und_gastroenterologie', $first->problemAreas[0]->code, 'ordered by code');
    }

    public function testProvisionalEntriesAreLeftOutAndAFormerMergeTravelsAsItsAlias(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $curation = self::getContainer()->get(MtsCatalogCuration::class);
        $provisional = new MtsDiagram('unbekannt', 'unbekannt', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional);
        $merged = new MtsDiagram('allergische_reaktion', 'Allergische Reaktion', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional);
        $entityManager->persist($provisional);
        $entityManager->persist($merged);
        $entityManager->flush();
        $curation->createAlias(new MtsAlias(MtsCatalogKind::Diagram, '', 'Unbekannt (alt)', 'unbekannt'));
        $allergy = $entityManager->getRepository(MtsDiagram::class)->findOneBy(['code' => 'allergie']);
        self::assertInstanceOf(MtsDiagram::class, $allergy);
        $curation->merge($merged, $allergy);

        $export = $this->exporter->export();
        $codes = array_map(static fn (CatalogEntryData $entry): string => $entry->code, $export->package->diagrams);

        self::assertCount(40, $codes);
        self::assertNotContains('unbekannt', $codes);
        self::assertNotContains('allergische_reaktion', $codes);
        self::assertEquals([new AliasData('diagram', 'allergische_reaktion', 'Allergische Reaktion', 'allergie')], $export->package->aliases);
        self::assertSame(['entries' => 1, 'aliases' => 1, 'assignments' => 0, 'rules' => 0], $export->omitted);
    }
}
