<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
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
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class ImportRunLifecycleTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;
    private ImportRunLifecycle $lifecycle;
    private ImportRunRepository $runs;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->lifecycle = $container->get(ImportRunLifecycle::class);
        $this->runs = $container->get(ImportRunRepository::class);
    }

    public function testRegisterCreatesQueuedRunAndMakesItCurrent(): void
    {
        $importId = $this->createImport(runCount: 0);
        $initiator = UserFactory::createOne(['username' => 'run-initiator']);

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Console, $initiator->getId());

        $this->em->clear();
        $stored = $this->runs->find($run->getId());
        self::assertInstanceOf(ImportRun::class, $stored);
        self::assertSame(ImportRunStatus::Queued, $stored->getStatus());
        self::assertSame(ImportRunTrigger::Console, $stored->getTrigger());
        self::assertSame($initiator->getId(), $stored->getInitiatedBy()?->getId());
        self::assertNull($stored->getStartedAt());
        self::assertEquals($run->getId(), $this->import($importId)->getCurrentRunId());
        self::assertSame(0, $this->import($importId)->getRunCount());
    }

    public function testRegisteringANewRunSupersedesTheUnfinishedPreviousRunAndItsBatchItem(): void
    {
        $importId = $this->createImport(runCount: 0);
        $item = $this->createBatchItem($importId);

        $previous = $this->lifecycle->register($importId, ImportRunTrigger::BatchRequeue, null, $item);
        $next = $this->lifecycle->register($importId, ImportRunTrigger::Console);

        $this->em->clear();
        $previousRun = $this->runs->find($previous->getId());
        self::assertInstanceOf(ImportRun::class, $previousRun);
        self::assertSame(ImportRunStatus::Superseded, $previousRun->getStatus());
        self::assertNotNull($previousRun->getFinishedAt());
        self::assertSame(ImportBatchRunItemStatus::Superseded, $previousRun->getBatchRunItem()?->getStatus());

        $import = $this->import($importId);
        self::assertEquals($next->getId(), $import->getCurrentRunId());
        self::assertSame(1, $import->getRunCount(), 'A superseded run is a finished run.');
        self::assertCount(2, $this->runs->findByImportId($importId));
    }

    public function testAFailingDispatchRollsBackTheRegistration(): void
    {
        $importId = $this->createImport(runCount: 0);
        $item = $this->createBatchItem($importId);
        $previous = $this->lifecycle->register($importId, ImportRunTrigger::BatchRequeue, null, $item);

        try {
            $this->lifecycle->register($importId, ImportRunTrigger::BatchRequeue, null, $item, static function (): never {
                throw new \RuntimeException('bus down');
            });
            self::fail('The dispatch error must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('bus down', $e->getMessage());
        }

        // The caller may flush again after a failed dispatch (the requeue batch does).
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->runs->findByImportId($importId), 'No run without a queued message is left behind.');
        $previousRun = $this->runs->find($previous->getId());
        self::assertInstanceOf(ImportRun::class, $previousRun);
        self::assertSame(ImportRunStatus::Queued, $previousRun->getStatus(), 'The previous run is not superseded by a rolled back registration.');
        self::assertEquals($previous->getId(), $this->import($importId)->getCurrentRunId());
        self::assertSame(0, $this->import($importId)->getRunCount());
        self::assertEquals($previous->getId(), $previousRun->getBatchRunItem()?->getImportRun()?->getId());
    }

    public function testRegisteringANewRunKeepsAFinishedPreviousRun(): void
    {
        $importId = $this->createImport(runCount: 0);
        $previous = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        self::assertTrue($this->lifecycle->fail($this->contextFor($importId, $previous), ImportRunReasonCode::FileNotFound));

        $this->lifecycle->register($importId, ImportRunTrigger::Console);

        $this->em->clear();
        $previousRun = $this->runs->find($previous->getId());
        self::assertInstanceOf(ImportRun::class, $previousRun);
        self::assertSame(ImportRunStatus::Failed, $previousRun->getStatus());
        self::assertSame(ImportRunReasonCode::FileNotFound, $previousRun->getReasonCode());
        self::assertSame(1, $this->import($importId)->getRunCount());
    }

    public function testStartClaimsTheCurrentRunOnlyWhileItIsOpen(): void
    {
        $importId = $this->createImport(runCount: 0);
        $run = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $message = new ImportAllocationsMessage($importId, $run->getId()->toRfc4122());

        $context = $this->lifecycle->start($message);
        self::assertInstanceOf(ImportRunContext::class, $context);
        self::assertFalse($context->resumed);

        $redelivered = $this->lifecycle->start($message);
        self::assertInstanceOf(ImportRunContext::class, $redelivered);
        self::assertTrue($redelivered->resumed, 'A redelivered message resumes the running run.');

        $this->lifecycle->fail($context, ImportRunReasonCode::ProcessingError);

        self::assertNull($this->lifecycle->start($message), 'A finished run is not processed again.');
    }

    public function testStartIgnoresMessagesOfAnotherRun(): void
    {
        $importId = $this->createImport(runCount: 0);
        $old = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $this->lifecycle->register($importId, ImportRunTrigger::Console);

        self::assertNull($this->lifecycle->start(new ImportAllocationsMessage($importId, $old->getId()->toRfc4122())));
        self::assertNull($this->lifecycle->start(new ImportAllocationsMessage($importId, Uuid::v7()->toRfc4122())));
        self::assertNull($this->lifecycle->start(new ImportAllocationsMessage($importId, 'not-a-uuid')));
    }

    public function testARunEndsExactlyOnceAndOnlyTheWinnerDispatchesTheEvent(): void
    {
        $importId = $this->createImport(runCount: 0);
        $run = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);

        /** @var list<ImportFailed> $events */
        $events = [];
        self::getContainer()->get(EventDispatcherInterface::class)
            ->addListener(ImportFailed::class, static function (ImportFailed $event) use (&$events): void {
                $events[] = $event;
            });

        self::assertTrue($this->lifecycle->fail($context, ImportRunReasonCode::InvalidFile));
        self::assertFalse($this->lifecycle->fail($context, ImportRunReasonCode::ProcessingError));
        self::assertFalse($this->lifecycle->failAfterRetriesExhausted(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122())));

        self::assertCount(1, $events);
        self::assertSame($run->getId()->toRfc4122(), $events[0]->runId);
        self::assertSame(ImportRunReasonCode::InvalidFile, $events[0]->reasonCode);

        $this->em->clear();
        self::assertSame(ImportRunReasonCode::InvalidFile, $this->runs->find($run->getId())?->getReasonCode());
        $import = $this->import($importId);
        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(1, $import->getRunCount());
    }

    public function testRetriesExhaustedEndsAnOpenRunAsFailed(): void
    {
        $importId = $this->createImport(runCount: 0);
        $run = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $message = new ImportAllocationsMessage($importId, $run->getId()->toRfc4122());
        $this->lifecycle->start($message);

        self::assertTrue($this->lifecycle->failAfterRetriesExhausted($message));

        $this->em->clear();
        $stored = $this->runs->find($run->getId());
        self::assertInstanceOf(ImportRun::class, $stored);
        self::assertSame(ImportRunStatus::Failed, $stored->getStatus());
        self::assertSame(ImportRunReasonCode::RetriesExhausted, $stored->getReasonCode());
    }

    public function testFailingASupersededRunChangesNothing(): void
    {
        $importId = $this->createImport(runCount: 0);
        $old = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $old->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        $this->lifecycle->register($importId, ImportRunTrigger::Console);

        self::assertFalse($this->lifecycle->fail($context, ImportRunReasonCode::ProcessingError));

        $this->em->clear();
        self::assertSame(ImportRunStatus::Superseded, $this->runs->find($old->getId())?->getStatus());
        self::assertSame(ImportStatus::PENDING, $this->import($importId)->getStatus());
    }

    public function testRunsAreDeletedWithTheirImport(): void
    {
        $importId = $this->createImport(runCount: 0);
        $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $this->em->clear();

        $this->em->remove($this->import($importId));
        $this->em->flush();

        self::assertSame([], $this->runs->findByImportId($importId));
    }

    private function contextFor(int $importId, ImportRun $run): ImportRunContext
    {
        return new ImportRunContext($importId, $run->getId());
    }

    private function createImport(int $runCount): int
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'runCount' => $runCount,
        ]);

        return (int) $import->getId();
    }

    private function createBatchItem(int $importId): ImportBatchRunItem
    {
        $batch = new ImportBatchRun();
        $item = new ImportBatchRunItem($importId, 'Batch item');
        $batch->addItem($item);
        $this->em->persist($batch);
        $this->em->flush();

        return $item;
    }

    private function import(int $importId): Import
    {
        $import = $this->em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }
}
