<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Application\Curation;

use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalogCuration;
use Cois\Plugin\EdVisit\Application\Curation\CurationExporter;
use Cois\Plugin\EdVisit\Application\Curation\Package\AliasData;
use Cois\Plugin\EdVisit\Application\Curation\Package\AreaRuleData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CatalogEntryData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageWriter;
use Cois\Plugin\EdVisit\Application\Curation\Package\ProblemAreaData;
use Cois\Plugin\EdVisit\Domain\Entity\MtsAlias;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagram;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagramIndicator;
use Cois\Plugin\EdVisit\Domain\Entity\MtsIndicator;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilityAreaDiagram;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilityProblemArea;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogSource;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
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
        // An assignment and a rule that name the provisional entry stay behind as well.
        $indicator = $entityManager->getRepository(MtsIndicator::class)->findOneBy([]);
        $area = $entityManager->getRepository(PlausibilityProblemArea::class)->findOneBy(['code' => 'anaphylaxie']);
        self::assertInstanceOf(MtsIndicator::class, $indicator);
        self::assertInstanceOf(PlausibilityProblemArea::class, $area);
        $entityManager->persist(new MtsDiagramIndicator($provisional, $indicator));
        $entityManager->persist(new PlausibilityAreaDiagram($area, $provisional, 1));
        $entityManager->flush();

        $export = $this->exporter->export();
        $codes = array_map(static fn (CatalogEntryData $entry): string => $entry->code, $export->package->diagrams);

        self::assertCount(40, $codes);
        self::assertNotContains('unbekannt', $codes);
        self::assertNotContains('allergische_reaktion', $codes);
        self::assertEquals([new AliasData('diagram', 'allergische_reaktion', 'Allergische Reaktion', 'allergie')], $export->package->aliases);
        self::assertSame(['entries' => 1, 'aliases' => 1, 'assignments' => 1, 'rules' => 1], $export->omitted);
        $anaphylaxis = array_values(array_filter($export->package->problemAreas, static fn (ProblemAreaData $data): bool => 'anaphylaxie' === $data->code))[0];
        self::assertEquals([new AreaRuleData('allergie', 3)], $anaphylaxis->diagrams);
    }
}
