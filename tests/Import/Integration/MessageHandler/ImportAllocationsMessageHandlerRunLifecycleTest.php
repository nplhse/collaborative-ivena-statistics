<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\EventSubscriber\ImportRunRetriesExhaustedSubscriber;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class ImportAllocationsMessageHandlerRunLifecycleTest extends ImportAllocationsMessageHandlerTestCase
{
    private ImportRunLifecycle $lifecycle;
    private ImportRunRepository $runs;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $this->runs = self::getContainer()->get(ImportRunRepository::class);
    }

    public function testRunMessageCompletesTheRunAndDispatchesTheOutcomeOnce(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $run = $this->lifecycle->register($id, ImportRunTrigger::Console);
        /** @var list<ImportCompleted> $completed */
        $completed = [];
        self::getContainer()->get(EventDispatcherInterface::class)
            ->addListener(ImportCompleted::class, static function (ImportCompleted $event) use (&$completed): void {
                $completed[] = $event;
            });

        $message = new ImportAllocationsMessage($id, $run->getId()->toRfc4122());
        $this->handler->__invoke($message);
        $this->handler->__invoke($message);

        self::assertCount(1, $completed);
        self::assertSame($run->getId()->toRfc4122(), $completed[0]->runId);

        $this->em->clear();
        $stored = $this->storedRun($run);
        self::assertSame(ImportRunStatus::Completed, $stored->getStatus());
        self::assertSame(1, $stored->getRowsTotal());
        self::assertSame(1, $stored->getRowsOk());
        self::assertSame(0, $stored->getRowsRejected());
        self::assertNotNull($stored->getStartedAt());
        self::assertNotNull($stored->getFinishedAt());
        self::assertNull($stored->getReasonCode());

        $import = $this->imports->find($id);
        self::assertNotNull($import);
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        self::assertSame(1, $import->getRunCount());
        self::assertSame(1, $this->countAllocationsForImport($id));
    }

    public function testMessageOfASupersededRunIsAcknowledgedWithoutProcessing(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $old = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $current = $this->lifecycle->register($id, ImportRunTrigger::Console);

        $this->handler->__invoke(new ImportAllocationsMessage($id, $old->getId()->toRfc4122()));

        $this->em->clear();
        self::assertSame(0, $this->countAllocationsForImport($id));
        self::assertSame(ImportStatus::PENDING, $this->imports->find($id)?->getStatus());
        self::assertSame(ImportRunStatus::Superseded, $this->storedRun($old)->getStatus());
        self::assertSame(ImportRunStatus::Queued, $this->storedRun($current)->getStatus());
    }

    public function testMissingFileEndsTheRunAsFailedWithAReasonCode(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        \unlink($csvPath);
        $run = $this->lifecycle->register($id, ImportRunTrigger::Console);
        /** @var list<ImportFailed> $failed */
        $failed = [];
        self::getContainer()->get(EventDispatcherInterface::class)
            ->addListener(ImportFailed::class, static function (ImportFailed $event) use (&$failed): void {
                $failed[] = $event;
            });

        $this->handler->__invoke(new ImportAllocationsMessage($id, $run->getId()->toRfc4122()));

        self::assertCount(1, $failed);
        self::assertSame(ImportRunReasonCode::FileNotFound, $failed[0]->reasonCode);
        self::assertStringNotContainsString($csvPath, (string) $failed[0]->reason, 'Reasons never contain paths or exception messages.');

        $this->em->clear();
        self::assertSame(ImportRunReasonCode::FileNotFound, $this->storedRun($run)->getReasonCode());
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testRetryableErrorIsRethrownAndLeavesTheRunOpenUntilRetriesAreExhausted(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $run = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $message = new ImportAllocationsMessage($id, $run->getId()->toRfc4122());
        $context = $this->lifecycle->start($message);
        self::assertInstanceOf(ImportRunContext::class, $context);

        $deadlock = new DeadlockException(new class('deadlock detected') extends AbstractException {
        }, null);

        try {
            new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'handleRunError')
                ->invoke($this->handler, $context, $deadlock, 10);
            self::fail('A retryable error must be rethrown.');
        } catch (DeadlockException $e) {
            self::assertSame($deadlock, $e);
        }

        $this->em->clear();
        self::assertSame(ImportRunStatus::Running, $this->storedRun($run)->getStatus());

        $subscriber = self::getContainer()->get(ImportRunRetriesExhaustedSubscriber::class);
        $subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'async_priority_high', $deadlock));

        $this->em->clear();
        $stored = $this->storedRun($run);
        self::assertSame(ImportRunStatus::Failed, $stored->getStatus());
        self::assertSame(ImportRunReasonCode::RetriesExhausted, $stored->getReasonCode());
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testNonRetryableErrorEndsTheRunAsFailed(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $run = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $context = $this->lifecycle->start(new ImportAllocationsMessage($id, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);

        new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'handleRunError')
            ->invoke($this->handler, $context, new \RuntimeException('Something broke at /secret/path'), 10);

        $this->em->clear();
        $stored = $this->storedRun($run);
        self::assertSame(ImportRunStatus::Failed, $stored->getStatus());
        self::assertSame(ImportRunReasonCode::ProcessingError, $stored->getReasonCode());
    }

    public function testRedeliveredRunRemovesItsEarlierChunksBeforeProcessingAgain(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $run = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $message = new ImportAllocationsMessage($id, $run->getId()->toRfc4122());
        $this->handler->__invoke($message);
        self::assertSame(1, $this->countAllocationsForImport($id));

        // Simulate a worker that committed its chunks and then crashed before the run ended.
        $this->em->getConnection()->executeStatement(
            "UPDATE import_run SET status = 'running', finished_at = NULL WHERE id = :id",
            ['id' => $run->getId()->toRfc4122()],
        );
        $this->em->getConnection()->executeStatement('UPDATE import SET run_count = 0 WHERE id = :id', ['id' => $id]);
        $this->em->clear();

        $this->handler->__invoke($message);

        $this->em->clear();
        self::assertSame(1, $this->countAllocationsForImport($id), 'The redelivery must not duplicate allocations.');
        self::assertSame(ImportRunStatus::Completed, $this->storedRun($run)->getStatus());
    }

    public function testSupersededRunCannotDeleteDataOfANewerRun(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $first = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $this->handler->__invoke(new ImportAllocationsMessage($id, $first->getId()->toRfc4122()));
        self::assertSame(1, $this->countAllocationsForImport($id));

        // Run A claims its message, then run B is registered (e.g. on another worker) before A cleans up.
        $runA = $this->lifecycle->register($id, ImportRunTrigger::Console);
        $contextA = $this->lifecycle->start(new ImportAllocationsMessage($id, $runA->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $contextA);
        $this->lifecycle->register($id, ImportRunTrigger::Console);

        $guard = self::getContainer()->get(ImportRunCommitGuard::class);
        $guard->activate($contextA);
        try {
            $import = $this->imports->find($id);
            self::assertNotNull($import);
            self::getContainer()->get(ImportPreviousRunCleanupService::class)->cleanup($import);
            self::fail('The superseded run must not clean up.');
        } catch (ImportRunSupersededException) {
        } finally {
            $guard->deactivate();
        }

        $this->em->clear();
        self::assertSame(1, $this->countAllocationsForImport($id), 'Data is kept for the newer run.');
    }

    public function testLegacyMessageIsProcessedWithoutCreatingARun(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();

        $this->handler->__invoke(new ImportAllocationsMessage($id));

        self::assertSame([], $this->runs->findByImportId($id));
        self::assertSame(ImportStatus::COMPLETED, $this->imports->find($id)?->getStatus());
    }

    private function storedRun(ImportRun $run): ImportRun
    {
        $stored = $this->runs->find($run->getId());
        self::assertInstanceOf(ImportRun::class, $stored);

        return $stored;
    }
}
