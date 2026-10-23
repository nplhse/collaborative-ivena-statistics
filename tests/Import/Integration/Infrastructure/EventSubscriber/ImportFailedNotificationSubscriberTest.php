<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;

final class ImportFailedNotificationSubscriberTest extends DatabaseKernelTestCase
{
    private ImportRunLifecycle $lifecycle;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testFailedRunNotifiesSystemRecipientsOnceWithItsReasonCode(): void
    {
        $admin = UserFactory::new()->asNotificationRecipient()->create(['email' => 'admin-import@example.test']);
        $adminWithoutOptIn = UserFactory::new()->asAdmin()->create();
        $creator = UserFactory::createOne();
        $importId = $this->createImport($creator, 'Broken allocation import');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        self::assertTrue($this->lifecycle->fail($context, ImportRunReasonCode::NoRows));

        // A second event for the same run (e.g. from a retry) creates nothing new.
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(
            new ImportFailed($importId, 'raw error', $run->getId()->toRfc4122(), ImportRunReasonCode::NoRows),
        );

        $rows = $this->connection->fetchAllAssociative("SELECT * FROM notification WHERE recipient_id = ? AND type = 'admin.import_failed'", [$admin->getId()]);
        self::assertCount(1, $rows);
        self::assertSame(
            ['importName' => 'Broken allocation import', 'hospitalName' => 'Notify Hospital', 'rowsTotal' => null, 'reason' => 'no_rows'],
            json_decode((string) $rows[0]['parameters'], true),
        );
        self::assertSame('role', $rows[0]['access_scope']);
        self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
        self::assertSame('high', $rows[0]['priority']);
        self::assertSame('app_import_show', $rows[0]['action_route']);
        self::assertSame(['import.failed'], $this->types($creator), 'the creator only gets the user notification');
        self::assertSame([], $this->types($adminWithoutOptIn));

        $email = $this->emailTo('admin-import@example.test');
        self::assertSame('Import „Broken allocation import“ failed', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Notify Hospital', $html);
        self::assertStringContainsString('The file contains no rows.', $html);
        self::assertStringContainsString('/import/'.$importId, $html);
        self::assertStringNotContainsString('raw error', $html);
    }

    public function testOnlyFailedRunsWithRunIdQualify(): void
    {
        $admin = UserFactory::new()->asNotificationRecipient()->create();
        $creator = UserFactory::createOne();
        $importId = $this->createImport($creator, 'Fine import');
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        $this->lifecycle->finish($context, static fn (): ImportRunOutcome => new ImportRunOutcome(ImportRunStatus::Completed, 5, 5, 0));

        $dispatcher->dispatch(new ImportFailed($importId, 'legacy message without run'));
        $dispatcher->dispatch(new ImportFailed($importId, null, 'not-a-uuid'));
        $dispatcher->dispatch(new ImportFailed($importId, null, '0193a5d0-0000-7000-8000-000000000000'));
        $dispatcher->dispatch(new ImportFailed($importId, null, $run->getId()->toRfc4122()));

        self::assertSame([], $this->types($admin), 'legacy, unknown and completed runs notify no admin');
    }

    public function testNoRecipientsMeansNoAdminNotification(): void
    {
        $creator = UserFactory::createOne();
        $importId = $this->createImport($creator, 'Nobody listens');

        $run = $this->lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $context = $this->lifecycle->start(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        self::assertTrue($this->lifecycle->fail($context, ImportRunReasonCode::InvalidFile));

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'admin.import_failed'"));
    }

    private function createImport(User $creator, string $name): int
    {
        $hospital = HospitalFactory::createOne(['owner' => $creator, 'name' => 'Notify Hospital']);

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

    private function emailTo(string $address): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && \in_array($address, array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true)) {
                return $message;
            }
        }

        self::fail(\sprintf('No email to %s.', $address));
    }
}
