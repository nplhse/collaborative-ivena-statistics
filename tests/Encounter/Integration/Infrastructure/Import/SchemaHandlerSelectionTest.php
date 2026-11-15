<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\Probe\ProbeDimension;
use App\Tests\Support\Encounter\Probe\ProbeSchemaHandler;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * First proof of the schema seam (ADR 037, #1274): a schema registered through the container like a
 * bundle's (`probe`, config/services/encounter_probe_test.yaml) is processed by its own handler in the
 * shared encounter import workflow, `ed_visit` keeps its handler, and a schema without handler is refused.
 */
#[ResetDatabase]
final class SchemaHandlerSelectionTest extends KernelTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testProbeFileIsProcessedByTheProbeHandlerInTheSharedWorkflow(): void
    {
        $events = $this->recordEvents();
        $importId = $this->uploadProbe($this->probeFile()
            ->row(['record_fingerprint' => $this->fingerprint('a'), 'case_key' => EncounterFileBuilder::caseKey('1'), 'recorded_at' => '2026-10-01T08:15'])
            ->row(['record_fingerprint' => $this->fingerprint('a'), 'case_key' => EncounterFileBuilder::caseKey('1'), 'recorded_at' => '2026-10-01T08:15'])
            ->row(['record_fingerprint' => str_repeat('0', 64), 'case_key' => EncounterFileBuilder::caseKey('2'), 'recorded_at' => '2026-10-02T09:00'])
            ->row(['record_fingerprint' => $this->fingerprint('b'), 'case_key' => EncounterFileBuilder::caseKey('3'), 'recorded_at' => ''])
            ->row(['record_fingerprint' => $this->fingerprint('c'), 'case_key' => EncounterFileBuilder::caseKey('4'), 'recorded_at' => '2026-10-03T10:30']));

        // The handler got the valid rows (lines 3, 4 and 7) with the import's context, inside the commit.
        $handler = $this->probeHandler();
        self::assertCount(1, $handler->writes);
        self::assertEquals(new SchemaWriteContext((int) $this->hospital->getId(), $importId, 1), $handler->writes[0]['context']);
        self::assertSame([3, 4, 7], $handler->writes[0]['lines']);

        // Counts, rejects, coverage and the key check value come from the shared workflow.
        $import = $this->import($importId);
        // One rejected row makes the run partial, as for any import.
        self::assertSame(ImportStatus::PARTIAL, $import->getStatus());
        self::assertSame(5, $import->getRowCount());
        self::assertSame(1, $import->getRowsRejected());
        self::assertSame(['2026-10-01' => 2, '2026-10-03' => 1], $import->getDataCoverage()?->dailyCounts);
        $encounterImport = $this->encounterImportOf($importId);
        self::assertSame(
            ['inserted' => 2, 'duplicates' => 1, 'skipped' => 1, 'rejected' => 1, 'suspected' => 0],
            [
                'inserted' => $encounterImport->getInserted(),
                'duplicates' => $encounterImport->getDuplicates(),
                'skipped' => $encounterImport->getSkipped(),
                'rejected' => $encounterImport->getRejected(),
                'suspected' => $encounterImport->getSuspectedDuplicates(),
            ],
        );
        self::assertSame([5], $this->connection()->fetchFirstColumn('SELECT line_number FROM import_reject WHERE import_id = ?', [$importId]));
        self::assertSame(EncounterFileBuilder::KEY_CHECK, $this->connection()->fetchOne('SELECT active_check_value FROM encounter_key_state WHERE hospital_id = ?', [$this->hospital->getId()]));

        // Nothing reached the ed_visit table, and no matching was announced.
        self::assertSame(0, $this->encounterCount($importId));
        self::assertCount(0, $events);
    }

    public function testEdVisitFilesKeepTheirHandler(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->build(), 'ed visits');

        self::assertSame([], $this->probeHandler()->writes);
        self::assertSame(1, $this->encounterCount($importId));
    }

    public function testReprocessingRemovesTheRecordsThroughEveryHandler(): void
    {
        $importId = $this->uploadProbe($this->probeFile()
            ->row(['record_fingerprint' => $this->fingerprint('a'), 'case_key' => EncounterFileBuilder::caseKey('1'), 'recorded_at' => '2026-10-01T08:15']));

        $this->runEncounterImport($importId, ImportRunTrigger::Reprocess, (int) $this->uploader->getId());

        self::assertSame([$importId], $this->probeHandler()->removedImports);
        self::assertCount(2, $this->probeHandler()->writes);
    }

    public function testSchemaWithoutHandlerIsRejectedAsUnsupported(): void
    {
        $importId = $this->storeEncounterFile($this->hospital, $this->uploader, $this->probeFile()
            ->manifest('schema', 'inpatient_stay')
            ->row(['record_fingerprint' => $this->fingerprint('a'), 'case_key' => EncounterFileBuilder::caseKey('1'), 'recorded_at' => '2026-10-01T08:15'])
            ->build(), 'no handler', schema: 'inpatient_stay');
        $this->runEncounterImport($importId, ImportRunTrigger::Upload, (int) $this->uploader->getId());

        self::assertSame(EncounterFileRejection::SchemaUnsupported, $this->encounterImportOf($importId)->getFileRejection());
        self::assertSame(ImportStatus::FAILED, $this->import($importId)->getStatus());
        self::assertSame([], $this->probeHandler()->writes);
    }

    private function probeFile(): EncounterFileBuilder
    {
        return EncounterFileBuilder::for(ProbeDimension::SCHEMA, ProbeDimension::header(), ProbeDimension::FINGERPRINT_RULE);
    }

    private function fingerprint(string $record): string
    {
        return hash('sha256', 'probe|'.$record);
    }

    private function uploadProbe(EncounterFileBuilder $file): int
    {
        return $this->uploadEncounterFile($this->hospital, $this->uploader, $file->build(), 'probe', schema: ProbeDimension::SCHEMA);
    }

    private function probeHandler(): ProbeSchemaHandler
    {
        $registry = self::getContainer()->get(EncounterSchemaHandlerRegistry::class);
        self::assertInstanceOf(EncounterSchemaHandlerRegistry::class, $registry);
        $handler = $registry->find(ProbeDimension::SCHEMA);
        self::assertInstanceOf(ProbeSchemaHandler::class, $handler);

        return $handler;
    }

    private function import(int $importId): Import
    {
        $em = $this->entityManager();
        $em->clear();
        $import = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    /**
     * @return \ArrayObject<int, EncountersImported>
     */
    private function recordEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, EncountersImported> $events */
        $events = new \ArrayObject();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(EncountersImported::class, static function (EncountersImported $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }
}
