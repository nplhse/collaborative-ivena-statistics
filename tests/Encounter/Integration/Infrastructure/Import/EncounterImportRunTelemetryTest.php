<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Message\ImportEncountersMessage;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Application\Contracts\ImportRunTelemetryInterface;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Infrastructure\EventSubscriber\ImportRunRetriesExhaustedSubscriber;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Encounter import runs are recorded as job `encounter_import` (ADR 018, #1070), also when Messenger
 * gives up after retries.
 */
#[ResetDatabase]
final class EncounterImportRunTelemetryTest extends KernelTestCase
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

    public function testASuccessfulRunIsRecordedWithItsRowCounters(): void
    {
        $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'first');
        // b is new, a is known from the first file: 2 rows, 2 ok, 1 already imported.
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T09:00')
            ->build(), 'second');

        $row = $this->connection()->fetchAssociative('SELECT job, triggered_by, status, attempt, hospital_id, metrics FROM job_run WHERE import_id = ?', [$importId]);
        self::assertIsArray($row);
        self::assertSame('encounter_import', $row['job']);
        self::assertSame('upload', $row['triggered_by']);
        self::assertSame('completed', $row['status']);
        self::assertSame(1, $row['attempt']);
        self::assertSame($this->hospital->getId(), $row['hospital_id']);
        self::assertSame(
            ['rows_ok' => 2, 'rows_total' => 2, 'rows_rejected' => 0, 'rows_deduplicated' => 1],
            json_decode((string) $row['metrics'], true),
            'Encounter imports record the row counters, no phase times.',
        );
    }

    public function testARejectedFileIsRecordedAsAFailedRun(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::rawVendorExport(), 'raw');

        self::assertSame(
            [['job' => 'encounter_import', 'status' => 'failed']],
            $this->connection()->fetchAllAssociative('SELECT job, status FROM job_run WHERE import_id = ?', [$importId]),
        );
    }

    public function testTheLastRetryingAttemptFailsAsEncounterImportWhenMessengerGivesUp(): void
    {
        $importId = $this->storeEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'retried');
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $run = $lifecycle->register($importId, ImportRunTrigger::Console);
        $message = new ImportEncountersMessage($importId, $run->getId()->toRfc4122());
        $context = $lifecycle->start($message);
        self::assertInstanceOf(ImportRunContext::class, $context);
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        // The handler's attempt ended with a retryable error, e.g. a lost database connection.
        $telemetry = self::getContainer()->get(ImportRunTelemetryInterface::class);
        $telemetry->begin($context, $import);
        $telemetry->end(ImportRunTelemetryInterface::STATUS_RETRYING, new \RuntimeException('connection lost'));

        self::getContainer()->get(ImportRunRetriesExhaustedSubscriber::class)
            ->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'async_priority_high', new \RuntimeException('connection lost')));

        self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM import_run WHERE id = ?', [$context->runId->toRfc4122()]));
        self::assertSame(
            [['job' => 'encounter_import', 'status' => 'failed', 'attempt' => 1]],
            $this->connection()->fetchAllAssociative('SELECT job, status, attempt FROM job_run WHERE import_run_id = ?', [$context->runId->toRfc4122()]),
        );
    }
}
