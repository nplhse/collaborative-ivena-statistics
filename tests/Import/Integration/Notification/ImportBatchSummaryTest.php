<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Notification;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportAllocationsDispatcher;
use App\Import\Application\Service\ImportBatchCompletion;
use App\Import\Application\Service\ImportRequeueBatchOrchestrator;
use App\Import\Application\Service\ImportRequeueResumeResolver;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Notification\ImportNotificationReconciler;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Batch re-imports end with one summary for their initiator (ADR 016, step 4), driven through the real
 * run lifecycle.
 */
final class ImportBatchSummaryTest extends DatabaseKernelTestCase
{
    private ImportRunLifecycle $lifecycle;
    private ImportBatchCompletion $completion;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $this->completion = self::getContainer()->get(ImportBatchCompletion::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testLastFinishedItemPublishesTheSummaryOnce(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $batch = $this->batch($admin, 3);
        [$first, $second, $third] = $this->dispatchAll($batch, $admin);
        $this->closeDispatch($batch);

        $this->finish($first, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));
        $this->finish($second, new ImportRunOutcome(ImportRunStatus::Partial, 5, 4, 1));
        self::assertSame([], $this->types($admin), 'no summary while an item is open, no notification per run');

        $context = $this->start($third);
        self::assertTrue($this->lifecycle->fail($context, ImportRunReasonCode::NoRows));

        // The initiator gets one summary, the high-priority variant because one run failed;
        // as an admin they also get the failed run like every admin.
        self::assertSame(['admin.import_failed', 'import.batch_failed'], $this->types($admin));
        $row = $this->connection->fetchAssociative("SELECT parameters, action_route, action_parameters, access_scope, required_role, priority FROM notification WHERE recipient_id = ? AND type = 'import.batch_failed'", [$admin->getId()]);
        self::assertIsArray($row);
        self::assertSame(
            ['batchId' => $batch->getId(), 'total' => 3, 'succeeded' => 2, 'completed' => 1, 'partial' => 1, 'failed' => 1, 'superseded' => 0],
            json_decode((string) $row['parameters'], true),
        );
        self::assertSame('high', $row['priority']);
        self::assertSame(['app_import_index', ['batch' => $batch->getId()], 'none', null], [
            $row['action_route'],
            json_decode((string) $row['action_parameters'], true),
            $row['access_scope'],
            $row['required_role'],
        ]);
        self::assertSame(['completed', 'partial', 'failed'], $this->itemStatuses($batch));
        self::assertNotNull($this->connection->fetchOne('SELECT processing_finished_at FROM import_batch_run WHERE id = ?', [$batch->getId()]));

        self::assertFalse($this->completion->complete((int) $batch->getId()), 'a finished batch is not finished again');
        self::assertSame(['admin.import_failed', 'import.batch_failed'], $this->types($admin), 'still one summary');
    }

    public function testBatchWhoseItemsFinishedDuringDispatchCompletesWhenTheDispatchCloses(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $batch = $this->batch($admin, 2);
        foreach ($this->dispatchAll($batch, $admin) as $run) {
            $this->finish($run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));
        }
        self::assertSame([], $this->types($admin), 'the dispatch phase is still open');

        self::assertTrue($this->closeDispatch($batch));

        self::assertSame(['import.batch_finished'], $this->types($admin));
    }

    public function testSupersededAndUndispatchedItemsCountAndANewRunCanFinishTheBatch(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $batch = $this->batch($admin, 2);
        $failedToDispatch = new ImportBatchRunItem(999_999, 'gone');
        $failedToDispatch->markRunning()->markDispatchFailed('Import #999999 not found');
        $batch->addItem($failedToDispatch);
        [$first, $second] = $this->dispatchAll($batch, $admin);
        $this->finish($first, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));
        $this->closeDispatch($batch);

        // An upload for the second import replaces the batch run before it finished.
        $this->lifecycle->register($second->getImport()->getId() ?? 0, ImportRunTrigger::Upload, $admin->getId());

        self::assertSame(['import.batch_failed'], $this->types($admin), 'the undispatched item counts as failed');
        $parameters = json_decode((string) $this->connection->fetchOne('SELECT parameters FROM notification WHERE recipient_id = ?', [$admin->getId()]), true);
        self::assertIsArray($parameters);
        self::assertSame([3, 1, 1, 1], [$parameters['total'], $parameters['completed'], $parameters['failed'], $parameters['superseded']]);
    }

    public function testOrchestratorKeepsOutcomesOfRunsThatFinishedDuringDispatch(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $batch = $this->batch($admin, 2);
        $importIds = array_map(static fn (ImportBatchRunItem $item): int => $item->getImportId(), $batch->getItems()->toArray());
        $this->entityManager->remove($batch);
        $this->entityManager->flush();

        // A worker that is faster than the orchestrator: every run ends while it is being dispatched.
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::assertInstanceOf(ImportAllocationsMessage::class, $message);
            $context = $this->lifecycle->start($message);
            self::assertInstanceOf(ImportRunContext::class, $context);
            $this->lifecycle->finish($context, static fn (): ImportRunOutcome => new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

            return new Envelope($message);
        });
        $container = self::getContainer();
        $orchestrator = new ImportRequeueBatchOrchestrator(
            $container->get(ImportRepository::class),
            $container->get(ImportBatchRunRepository::class),
            new ImportAllocationsDispatcher($container->get(ImportRepository::class), $bus, $container->get(TokenStorageInterface::class), $this->lifecycle),
            new ImportRequeueResumeResolver(),
            $this->completion,
            new NativeClock(),
        );

        $summary = $orchestrator->run(new ImportRequeueBatchOptions(onlyIds: $importIds, initiatedByUserId: $admin->getId()));

        self::assertSame(2, $summary->dispatched);
        $finished = $this->entityManager->find(ImportBatchRun::class, $summary->runId);
        self::assertInstanceOf(ImportBatchRun::class, $finished);
        self::assertSame(['completed', 'completed'], $this->itemStatuses($finished));
        self::assertSame(['import.batch_finished'], $this->types($admin));
    }

    public function testBatchWithoutInitiatorFinishesSilently(): void
    {
        $batch = $this->batch(null, 1);
        [$run] = $this->dispatchAll($batch, null);
        $this->closeDispatch($batch);

        $this->finish($run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        self::assertNotNull($this->connection->fetchOne('SELECT processing_finished_at FROM import_batch_run WHERE id = ?', [$batch->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM notification WHERE type LIKE 'import.%'"));
    }

    public function testReconciliationCompletesStuckBatchesAndRepublishesLostSummaries(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $batch = $this->batch($admin, 1);
        [$run] = $this->dispatchAll($batch, $admin);
        $this->finish($run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        // A crash after closing the dispatch phase: the batch is closed but never completed.
        $this->connection->executeStatement('UPDATE import_batch_run SET status = ?, dispatch_closed_at = NOW() WHERE id = ?', ['finished', $batch->getId()]);

        $reconciler = self::getContainer()->get(ImportNotificationReconciler::class);
        $from = new \DateTimeImmutable('-1 hour');
        $to = new \DateTimeImmutable('+1 hour');

        $reconciler->reconcile($from, $to);
        self::assertSame(['import.batch_finished'], $this->types($admin));

        // Lost between commit and inbox: published again from the batch record.
        $this->connection->executeStatement('DELETE FROM notification');
        $this->connection->executeStatement('DELETE FROM notification_deduplication');
        $reconciler->reconcile($from, $to);
        $reconciler->reconcile($from, $to);
        self::assertSame(['import.batch_finished'], $this->types($admin), 'once, however often reconciliation runs');

        $this->connection->executeStatement('DELETE FROM notification');
        $this->connection->executeStatement('DELETE FROM notification_deduplication');
        $reconciler->reconcile(new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-2 days'));
        self::assertSame([], $this->types($admin), 'batches outside the range are left alone');
    }

    public function testCompletionCheckNeedsTheCallersTransaction(): void
    {
        $this->expectException(\LogicException::class);

        $this->completion->completeIfFinished($this->connection, 1, new \DateTimeImmutable());
    }

    private function batch(?User $initiator, int $imports): ImportBatchRun
    {
        $batch = new ImportBatchRun(['initiatedByUserId' => $initiator?->getId()]);
        $hospital = HospitalFactory::createOne(['owner' => UserFactory::new()]);

        for ($i = 1; $i <= $imports; ++$i) {
            $import = ImportFactory::createOne([
                'name' => 'Batch import '.$i,
                'hospital' => $hospital,
                'createdBy' => UserFactory::new(),
                'type' => ImportType::ALLOCATION,
                'status' => ImportStatus::PENDING,
                'runCount' => 0,
            ]);
            $batch->addItem(new ImportBatchRunItem((int) $import->getId(), $import->getName()));
        }

        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    /**
     * @return list<ImportRun>
     */
    private function dispatchAll(ImportBatchRun $batch, ?User $initiator): array
    {
        $runs = [];
        foreach ($batch->getItems() as $item) {
            if (ImportBatchRunItemStatus::Pending !== $item->getStatus()) {
                continue;
            }

            $item->markRunning();
            $this->entityManager->flush();
            $runs[] = $this->lifecycle->register($item->getImportId(), ImportRunTrigger::BatchRequeue, $initiator?->getId(), $item);
            self::assertSame(ImportBatchRunItemStatus::Queued, $item->getStatus(), 'queued in the registration transaction');
        }

        return $runs;
    }

    private function closeDispatch(ImportBatchRun $batch): bool
    {
        $batch->closeDispatch(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->completion->complete((int) $batch->getId());
    }

    private function start(ImportRun $run): ImportRunContext
    {
        $context = $this->lifecycle->start(new ImportAllocationsMessage((int) $run->getImport()->getId(), $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);

        return $context;
    }

    private function finish(ImportRun $run, ImportRunOutcome $outcome): void
    {
        self::assertSame($outcome, $this->lifecycle->finish($this->start($run), static fn (): ImportRunOutcome => $outcome));
    }

    /**
     * @return list<string>
     */
    private function itemStatuses(ImportBatchRun $batch): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT status FROM import_batch_run_item WHERE run_id = ? ORDER BY id', [$batch->getId()]));
    }

    /**
     * @return list<string>
     */
    private function types(User $user): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT type FROM notification WHERE recipient_id = ? ORDER BY type', [$user->getId()]));
    }
}
