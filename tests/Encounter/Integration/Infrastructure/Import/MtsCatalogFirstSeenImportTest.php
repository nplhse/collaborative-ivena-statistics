<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogFirstSeenWriter;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Provisional MTS catalog entries for codes seen for the first time (ADR 032, #1157).
 *
 * The seed has 40 curated diagrams (among them `thoraxschmerz`) and 70 curated indicators (among them `heiss`), no
 * aliases. Only codes of visits the import stores create entries; codes that resolve create nothing; the visits keep
 * the codes as sent.
 */
#[ResetDatabase]
final class MtsCatalogFirstSeenImportTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-14 09:30:00');
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testUnknownCodesOfStoredVisitsBecomeProvisionalEntriesOnce(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15', overrides: ['mts_diagram' => 'thoraxschmerz', 'mts_indicator' => 'heiss'])
            ->visit('b', '2026-10-01T09:15', overrides: ['mts_diagram' => 'neues_diagramm', 'mts_indicator' => 'neuer_indikator', 'retriage_diagram' => 'retriage_neu', 'retriage_indicator' => 'heiss'])
            ->visit('c', '2026-10-01T10:15', overrides: ['mts_diagram' => 'neues_diagramm']));

        self::assertSame(3, $this->encounterCount($first));
        self::assertSame([
            ['code' => 'neues_diagramm', 'german_name' => 'neues_diagramm', 'source' => 'first_seen', 'status' => 'provisional', 'first_seen_at' => '2026-11-14 09:30:00'],
            ['code' => 'retriage_neu', 'german_name' => 'retriage_neu', 'source' => 'first_seen', 'status' => 'provisional', 'first_seen_at' => '2026-11-14 09:30:00'],
        ], $this->firstSeen('encounter_mts_diagram'));
        self::assertSame([
            ['code' => 'neuer_indikator', 'german_name' => 'neuer_indikator', 'source' => 'first_seen', 'status' => 'provisional', 'first_seen_at' => '2026-11-14 09:30:00'],
        ], $this->firstSeen('encounter_mts_indicator'));
        self::assertSame(42, $this->rows('encounter_mts_diagram'), '40 seeded + 2 new');
        self::assertSame(71, $this->rows('encounter_mts_indicator'), '70 seeded + 1 new');

        $audit = $this->connection()->fetchAllAssociative(
            "SELECT entity_class, action, metadata->'intent_metadata'->>'code' AS code, metadata->'intent_metadata'->>'import_id' AS import_id FROM audit_log WHERE intent = ? ORDER BY id",
            [MtsCatalogFirstSeenWriter::INTENT],
        );
        self::assertSame([
            ['entity_class' => MtsDiagram::class, 'action' => 'create', 'code' => 'neues_diagramm', 'import_id' => (string) $first],
            ['entity_class' => MtsDiagram::class, 'action' => 'create', 'code' => 'retriage_neu', 'import_id' => (string) $first],
            ['entity_class' => MtsIndicator::class, 'action' => 'create', 'code' => 'neuer_indikator', 'import_id' => (string) $first],
        ], $audit);

        // A later import with the same codes reuses the entries.
        self::mockTime('2026-11-15 12:00:00');
        $second = $this->upload(EdVisitFileBuilder::create()
            ->visit('d', '2026-10-02T08:15', overrides: ['mts_diagram' => 'neues_diagramm', 'mts_indicator' => 'neuer_indikator']), 'second');

        self::assertSame(1, $this->encounterCount($second));
        self::assertSame(42, $this->rows('encounter_mts_diagram'));
        self::assertSame(71, $this->rows('encounter_mts_indicator'));
        self::assertSame('2026-11-14 09:30:00', $this->firstSeen('encounter_mts_diagram')[0]['first_seen_at'], 'first seen stays the first import');
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE intent = ?', [MtsCatalogFirstSeenWriter::INTENT]));
    }

    public function testAliasCodesAreStoredAsSentAndCreateNothing(): void
    {
        $this->curation()->createAlias(new MtsAlias(MtsCatalogKind::Diagram, '', 'Brust Schmerzen', 'thoraxschmerz'));

        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15', overrides: ['mts_diagram' => 'brust_schmerzen']));

        self::assertSame(['brust_schmerzen'], $this->connection()->fetchFirstColumn('SELECT mts_diagram FROM encounter WHERE import_id = ?', [$importId]));
        self::assertSame([], $this->firstSeen('encounter_mts_diagram'));
    }

    public function testRejectedRowsAndKnownVisitsCreateNothing(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'));

        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15', overrides: ['mts_diagram' => 'nur_bekannter_besuch'])
            ->visit('b', '2026-10-01T09:15', overrides: ['mts_diagram' => 'abgelehnte_zeile', 'spo2' => '120']), 'second');

        self::assertSame(0, $this->encounterImportOf($importId)->getInserted());
        self::assertSame(1, $this->encounterImportOf($importId)->getDuplicates());
        self::assertSame(1, $this->encounterImportOf($importId)->getRejected());
        self::assertSame([], $this->firstSeen('encounter_mts_diagram'));
    }

    public function testBackfillCreatesEntriesForStoredCodesOnce(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15', overrides: ['mts_diagram' => 'altes_diagramm', 'retriage_indicator' => 'alter_indikator'])
            ->visit('b', '2026-10-01T09:15', overrides: ['mts_diagram' => 'thoraxschmerz']));
        // Encounters imported before #1157 have no entries: remove the ones the import created.
        $this->connection()->executeStatement("DELETE FROM encounter_mts_diagram WHERE source = 'first_seen'");
        $this->connection()->executeStatement("DELETE FROM encounter_mts_indicator WHERE source = 'first_seen'");
        $this->connection()->executeStatement("UPDATE encounter SET created_at = '2026-10-03 07:00:00' WHERE import_id = ?", [$importId]);

        $tester = new CommandTester(new Application(self::$kernel)->find('app:encounter:mts-catalog:backfill-first-seen'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 provisional MTS catalog entries created', $tester->getDisplay());
        self::assertSame(['altes_diagramm'], array_column($this->firstSeen('encounter_mts_diagram'), 'code'));
        self::assertSame('2026-10-03 07:00:00', $this->firstSeen('encounter_mts_diagram')[0]['first_seen_at'], 'first seen = oldest stored encounter');
        self::assertSame(['alter_indikator'], array_column($this->firstSeen('encounter_mts_indicator'), 'code'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('no entry was created', $tester->getDisplay());
        self::assertSame(41, $this->rows('encounter_mts_diagram'));
    }

    private function upload(EdVisitFileBuilder $file, string $name = 'encounters'): int
    {
        return $this->uploadEncounterFile($this->hospital, $this->uploader, $file->build(), $name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function firstSeen(string $table): array
    {
        return $this->connection()->fetchAllAssociative(\sprintf(
            "SELECT code, german_name, source, status, first_seen_at FROM %s WHERE source = 'first_seen' ORDER BY code",
            $table,
        ));
    }

    private function rows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function curation(): MtsCatalogCuration
    {
        return self::getContainer()->get(MtsCatalogCuration::class);
    }
}
