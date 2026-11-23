<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\EventSubscriber\ImportRunRetriesExhaustedSubscriber;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * A failed run removes what it wrote and nothing else. Every fixture holds 1 valid allocation.
 */
final class ImportAllocationsMessageHandlerFailedRunRowsTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testRejectionRatioDiscardsTheRowsAndKeepsRecordRejectsAndFile(): void
    {
        ['id' => $sourceId, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        // 1 valid + 1 rejected row: 50 % rejected.
        $id = $this->arrangeImportOfSameHospital($sourceId, $this->csvWithRejectedRows($csvPath, 1));

        $this->processInNewRun($id);

        $this->em->clear();
        $import = $this->imports->find($id);
        self::assertNotNull($import);
        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(2, $import->getRowCount());
        self::assertSame(1, $import->getRowsPassed());
        self::assertSame(1, $import->getRowsRejected());
        self::assertSame(0, $this->countAllocationsForImport($id));
        self::assertSame(0, $this->countProjectionRowsForImport($id));
        self::assertSame(1, $this->countRejects($id));
        self::assertNotNull($import->getFilePath());
    }

    public function testNonRetryableErrorDiscardsTheRowsOfTheRun(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        [$context] = $this->startRunThatHasWrittenRows($id);

        new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'handleRunError')
            ->invoke($this->handler, $context, new \RuntimeException('broken'), 10);

        $this->em->clear();
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
        self::assertSame(0, $this->countAllocationsForImport($id));
        self::assertSame(0, $this->countProjectionRowsForImport($id));
    }

    public function testRetryableErrorKeepsTheRowsUntilRetriesAreExhausted(): void
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        [$context, $message] = $this->startRunThatHasWrittenRows($id);
        $deadlock = new DeadlockException(new class('deadlock detected') extends AbstractException {
        }, null);

        try {
            new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'handleRunError')
                ->invoke($this->handler, $context, $deadlock, 10);
            self::fail('A retryable error must be rethrown.');
        } catch (DeadlockException) {
        }

        $this->em->clear();
        self::assertSame(1, $this->countAllocationsForImport($id));

        self::getContainer()->get(ImportRunRetriesExhaustedSubscriber::class)
            ->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'async_priority_high', $deadlock));

        $this->em->clear();
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
        self::assertSame(0, $this->countAllocationsForImport($id));
    }

    public function testFailedPreconditionOnAReprocessKeepsTheRowsOfTheLastSuccessfulRun(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        $this->processInNewRun($id);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($id);
        \unlink($csvPath);

        $this->processInNewRun($id);

        $this->em->clear();
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
        self::assertSame(1, $this->countAllocationsForImport($id));
        self::assertSame(1, $this->countProjectionRowsForImport($id));
    }

    /**
     * Status Running means the previous rows were cleaned, so the 1 row of the first run stands for rows of this run.
     *
     * @return array{ImportRunContext, ImportAllocationsMessage}
     */
    private function startRunThatHasWrittenRows(int $id): array
    {
        $this->processInNewRun($id);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($id);
        $this->em->getConnection()->executeStatement('UPDATE import SET status = ? WHERE id = ?', [ImportStatus::RUNNING->value, $id]);
        $this->em->clear();

        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $run = $lifecycle->register($id, ImportRunTrigger::Console);
        $message = new ImportAllocationsMessage($id, $run->getId()->toRfc4122());
        $context = $lifecycle->start($message);
        self::assertInstanceOf(ImportRunContext::class, $context);

        return [$context, $message];
    }

    private function countRejects(int $importId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]);
    }
}
