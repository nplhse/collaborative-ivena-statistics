<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Notification;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Event\ImportMilestoneReached;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Notification\ImportNotificationReconciler;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * End-to-end through the real run lifecycle: a finished run puts the right notifications into the
 * right inboxes (ADR 016, step 3b).
 */
final class ImportNotificationsTest extends DatabaseKernelTestCase
{
    private ImportRunLifecycle $lifecycle;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testUploadNotifiesCreatorAndOwnerAndRecordsTheFirstImportOnce(): void
    {
        $creator = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $importId = $this->createImport($creator, $owner, 'Januar');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $this->finish($importId, $run, new ImportRunOutcome(ImportRunStatus::Partial, 1200, 1180, 20));

        $creatorTypes = $this->types($creator);
        self::assertSame(['import.completed', 'user.first_import'], $creatorTypes);
        self::assertSame(['import.completed'], $this->types($owner));

        $row = $this->connection->fetchAssociative("SELECT * FROM notification WHERE recipient_id = ? AND type = 'import.completed'", [$creator->getId()]);
        self::assertIsArray($row);
        self::assertSame(
            ['importName' => 'Januar', 'hospitalName' => 'Klinikum Test', 'rowsTotal' => 1200, 'rowsOk' => 1180, 'rowsRejected' => 20, 'reason' => 'other'],
            json_decode((string) $row['parameters'], true),
        );
        self::assertSame('hospital', $row['access_scope']);
        self::assertSame('app_import_show', $row['action_route']);
        self::assertSame(['id' => $importId], json_decode((string) $row['action_parameters'], true));
        self::assertNotNull($row['toast_pending_until']);

        $second = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $this->finish($importId, $second, new ImportRunOutcome(ImportRunStatus::Completed, 10, 10, 0));

        self::assertSame(['import.completed', 'import.completed', 'user.first_import'], $this->types($creator), 'a second run notifies again, the milestone does not');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_import_milestone WHERE user_id = ?', [$creator->getId()]));
    }

    public function testCreatorWhoIsAlsoOwnerGetsOneNotification(): void
    {
        $creator = UserFactory::createOne();
        $importId = $this->createImport($creator, $creator, 'Februar');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $this->finish($importId, $run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        self::assertSame(['import.completed', 'user.first_import'], $this->types($creator));
    }

    public function testFailedRunNotifiesWithItsReasonCodeAndRecordsNoMilestone(): void
    {
        $creator = UserFactory::createOne();
        $importId = $this->createImport($creator, UserFactory::createOne(), 'März');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        self::assertTrue($this->lifecycle->fail($context, ImportRunReasonCode::NoRows));

        self::assertSame(['import.failed'], $this->types($creator));
        $parameters = json_decode((string) $this->connection->fetchOne('SELECT parameters FROM notification WHERE recipient_id = ?', [$creator->getId()]), true);
        self::assertIsArray($parameters);
        self::assertSame('no_rows', $parameters['reason']);
        self::assertSame('high', $this->connection->fetchOne('SELECT priority FROM notification WHERE recipient_id = ?', [$creator->getId()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_import_milestone WHERE user_id = ?', [$creator->getId()]));
    }

    public function testConsoleRunNotifiesOnlyItsInitiator(): void
    {
        $creator = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $importId = $this->createImport($creator, $owner, 'April');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Console, $admin->getId());
        $this->finish($importId, $run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        self::assertSame(['import.completed'], $this->types($admin));
        self::assertSame(['user.first_import'], $this->types($creator), 'the milestone still belongs to the creator');
        self::assertSame([], $this->types($owner));
    }

    public function testReprocessRunNotifiesOnlyTheUserWhoStartedIt(): void
    {
        $creator = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $importId = $this->createImport($creator, $owner, 'Juni');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Reprocess, $owner->getId());
        $this->finish($importId, $run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        self::assertSame(['import.completed'], $this->types($owner));
        self::assertSame(['user.first_import'], $this->types($creator), 'the creator only gets the milestone');
    }

    public function testConsoleRunWithoutInitiatorAndBatchRunsNotifyNobody(): void
    {
        $creator = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $importId = $this->createImport($creator, $owner, 'Mai');

        $console = $this->lifecycle->register($importId, ImportRunTrigger::Console);
        $this->finish($importId, $console, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));
        $batch = $this->lifecycle->register($importId, ImportRunTrigger::BatchRequeue, $owner->getId());
        $this->finish($importId, $batch, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        self::assertSame(['user.first_import'], $this->types($creator));
        self::assertSame([], $this->types($owner));
    }

    public function testEventsWithoutKnownRunAreIgnored(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $dispatcher->dispatch(new ImportCompleted(1));
        $dispatcher->dispatch(new ImportFailed(1, null, 'not-a-uuid'));
        $dispatcher->dispatch(new ImportCompleted(1, '0192f0c3-0000-7000-8000-000000000000'));
        $dispatcher->dispatch(new ImportMilestoneReached(999_999));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification'));
    }

    public function testReconciliationRepublishesLostOutcomesButNotBackfilledMilestones(): void
    {
        $creator = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $importId = $this->createImport($creator, $owner, 'Juni');
        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $this->finish($importId, $run, new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        $backfilled = UserFactory::createOne();
        $this->connection->executeStatement(
            "INSERT INTO user_import_milestone (user_id, milestone_type, import_run_id, achieved_at) VALUES (?, 'first_import', NULL, NOW())",
            [$backfilled->getId()],
        );

        // Simulate notifications lost between commit and publish.
        $this->connection->executeStatement('DELETE FROM notification');
        $this->connection->executeStatement('DELETE FROM notification_deduplication');

        $reconciler = self::getContainer()->get(ImportNotificationReconciler::class);
        $from = new \DateTimeImmutable('-1 hour');
        $to = new \DateTimeImmutable('+1 hour');

        self::assertSame(2, $reconciler->reconcile($from, $to), 'one run and one recorded milestone');
        self::assertSame(['import.completed', 'user.first_import'], $this->types($creator));
        self::assertSame(['import.completed'], $this->types($owner));
        self::assertSame([], $this->types($backfilled));

        $reconciler->reconcile($from, $to);
        self::assertSame(['import.completed', 'user.first_import'], $this->types($creator), 'a second reconciliation creates no duplicates');
        self::assertSame(0, $reconciler->reconcile(new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-2 days')));
    }

    private function finish(int $importId, ImportRun $run, ImportRunOutcome $outcome): void
    {
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        self::assertSame($outcome, $this->lifecycle->finish($context, static fn (): ImportRunOutcome => $outcome));
    }

    private function createImport(User $creator, User $owner, string $name): int
    {
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Klinikum Test']);

        return (int) ImportFactory::createOne([
            'name' => $name,
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'runCount' => 0,
        ])->getId();
    }

    /**
     * @return list<string>
     */
    private function types(User $user): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT type FROM notification WHERE recipient_id = ? ORDER BY type', [$user->getId()]));
    }
}
