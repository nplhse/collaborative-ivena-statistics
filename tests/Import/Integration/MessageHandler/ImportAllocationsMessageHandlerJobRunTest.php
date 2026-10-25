<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Enum\ImportRunTrigger;
use Doctrine\DBAL\Connection;

/**
 * Every attempt of an allocation import run is recorded in job_run (ADR 018), and its counters match the import.
 */
final class ImportAllocationsMessageHandlerJobRunTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testAFinishedRunIsRecordedWithTheCountersOfTheImport(): void
    {
        ['id' => $id] = $this->arrangeAllocationImportSampleCsvImport();
        $run = self::getContainer()->get(ImportRunLifecycle::class)->register($id, ImportRunTrigger::Console);

        $this->handler->__invoke(new ImportAllocationsMessage($id, $run->getId()->toRfc4122()));

        $jobRun = $this->onlyJobRun($id);
        self::assertSame('allocation_import', $jobRun['job']);
        self::assertSame('console', $jobRun['triggered_by']);
        $runStatus = $this->connection()->fetchOne('SELECT status FROM import_run WHERE id = ?', [$run->getId()->toRfc4122()]);
        self::assertContains($runStatus, ['completed', 'partial']);
        self::assertSame($runStatus, $jobRun['status'], 'job_run carries the terminal status of the import run.');
        self::assertSame(1, (int) $jobRun['attempt']);
        self::assertSame($run->getId()->toRfc4122(), $jobRun['import_run_id']);
        self::assertNotNull($jobRun['finished_at']);
        self::assertGreaterThan(0, (int) $jobRun['peak_memory_bytes']);
        self::assertNull($jobRun['error_class']);

        $import = $this->connection()->fetchAssociative(
            'SELECT hospital_id, row_count, rows_passed, rows_rejected, rows_deduplicated FROM import WHERE id = ?',
            [$id],
        );
        self::assertIsArray($import);
        self::assertSame((int) $import['hospital_id'], (int) $jobRun['hospital_id']);

        $metrics = $this->metrics($jobRun);
        self::assertSame((int) $import['row_count'], $metrics['rows_total']);
        self::assertSame((int) $import['rows_passed'], $metrics['rows_ok']);
        self::assertSame((int) $import['rows_rejected'], $metrics['rows_rejected']);
        self::assertSame((int) $import['rows_deduplicated'], $metrics['rows_deduplicated']);
        self::assertGreaterThan(0, $metrics['rows_total']);
        self::assertGreaterThanOrEqual(1, $metrics['chunks'], 'The final flush of the importer is a chunk.');
        foreach (['read_ms', 'map_ms', 'persist_ms'] as $phase) {
            self::assertIsInt($metrics[$phase]);
            self::assertGreaterThanOrEqual(0, $metrics[$phase]);
        }
    }

    public function testAMissingFileIsRecordedAsAFailedRun(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        \unlink($csvPath);
        $run = self::getContainer()->get(ImportRunLifecycle::class)->register($id, ImportRunTrigger::Console);

        $this->handler->__invoke(new ImportAllocationsMessage($id, $run->getId()->toRfc4122()));

        $jobRun = $this->onlyJobRun($id);
        self::assertSame('failed', $jobRun['status']);
        self::assertNotNull($jobRun['finished_at']);
        self::assertArrayNotHasKey('rows_total', $this->metrics($jobRun));
    }

    public function testARunSupersededDuringProcessingIsRecordedAsSuperseded(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $runA = $lifecycle->register($id, ImportRunTrigger::Console);
        $contextA = $lifecycle->start(new ImportAllocationsMessage($id, $runA->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $contextA);
        $lifecycle->register($id, ImportRunTrigger::Console);

        new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'processRun')
            ->invoke($this->handler, $contextA);

        self::assertSame('superseded', $this->onlyJobRun($id)['status']);
    }

    public function testAStaleMessageRecordsNoRun(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $old = $lifecycle->register($id, ImportRunTrigger::Console);
        $lifecycle->register($id, ImportRunTrigger::Console);

        $this->handler->__invoke(new ImportAllocationsMessage($id, $old->getId()->toRfc4122()));

        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM job_run WHERE job = 'allocation_import' AND import_id = ?", [$id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyJobRun(int $importId): array
    {
        $rows = $this->connection()->fetchAllAssociative("SELECT * FROM job_run WHERE job = 'allocation_import' AND import_id = ?", [$importId]);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $jobRun
     *
     * @return array<string, mixed>
     */
    private function metrics(array $jobRun): array
    {
        $metrics = json_decode((string) $jobRun['metrics'], true);
        self::assertIsArray($metrics);

        return $metrics;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
