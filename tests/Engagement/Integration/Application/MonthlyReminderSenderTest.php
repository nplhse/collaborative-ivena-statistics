<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderSender;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Lock\LockFactory;

final class MonthlyReminderSenderTest extends DatabaseKernelTestCase
{
    use MailerAssertionsTrait;
    private MonthlyReminderSender $sender;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sender = self::getContainer()->get(MonthlyReminderSender::class);
    }

    public function testSchedulerTriggerSkipsWhenUserOptedOut(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        $errors = $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler);

        self::assertSame(['monthly_reminder.error.opted_out'], $errors);
    }

    public function testCliTriggerSkipsWhenUserOptedOut(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        $errors = $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Cli);

        self::assertSame(['monthly_reminder.error.opted_out'], $errors);
    }

    public function testAdminTriggerRespectsTheOwnersSettings(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        self::assertSame(['monthly_reminder.error.opted_out'], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));
        self::assertEmailCount(0);
    }

    public function testAdminReminderToAnOwnerWithoutEmailIsOnlyAWebEntry(): void
    {
        $owner = UserFactory::createOne(['email' => sprintf('web-only-%s@example.test', bin2hex(random_bytes(4))), 'isVerified' => true]);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled) VALUES (?, 'type', 'engagement.monthly_reminder', 'email', false), (?, 'type', 'engagement.monthly_reminder', 'in_app', true)",
            [$owner->getId(), $owner->getId()],
        );
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

        self::assertEmailCount(0);
        self::assertSame(['engagement.monthly_reminder'], self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT type FROM notification WHERE recipient_id = ? AND in_app_visible = true',
            [$owner->getId()],
        ));
    }

    public function testSkipsWhenHospitalIsNotParticipating(): void
    {
        $hospital = $this->createHospital(optedOut: false, participating: false);

        self::assertSame(
            ['monthly_reminder.error.not_participating'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testSkipsWhenHospitalHasNoOwner(): void
    {
        $creator = UserFactory::createOne([
            'email' => sprintf('creator-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => null,
            'createdBy' => $creator,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        self::assertSame(
            ['monthly_reminder.error.no_owner'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testSkipsWhenOwnerEmailIsBlank(): void
    {
        $owner = UserFactory::createOne([
            'email' => '   ',
            'isVerified' => true,
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame(
            ['monthly_reminder.error.no_email'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testSkipsWhenOwnerIsNotVerified(): void
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('unverified-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => false,
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame(
            ['monthly_reminder.error.email_not_verified'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testAdminTriggerReturnsErrorWhenManualLockIsAlreadyHeld(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $hospitalId = (int) $hospital->getId();
        $lock = self::getContainer()->get(LockFactory::class)->createLock(sprintf('reminder-manual-%d', $hospitalId));
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(
                ['monthly_reminder.error.already_sent_recently'],
                $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin),
            );
        } finally {
            $lock->release();
        }
    }

    public function testAdminTriggerReturnsErrorWhenAlreadySentRecently(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Admin,
            $referenceDate,
        ));

        self::assertSame(
            ['monthly_reminder.error.already_sent_recently'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin, $referenceDate),
        );
    }

    public function testSchedulerSendPersistsDispatchLogForReportingPeriod(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Scheduler,
            $referenceDate,
        ));

        $dispatchRepository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);
        self::assertTrue($dispatchRepository->existsForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        ));

        $dispatch = $dispatchRepository->findOneBy([
            'hospital' => $hospital->getId(),
            'reportingPeriod' => '2026-06',
            'trigger' => MonthlyReminderTrigger::Scheduler->value,
        ]);
        self::assertNotNull($dispatch);
        self::assertSame('2026-06', $dispatch->getReportingPeriod());
        self::assertSame(MonthlyReminderTrigger::Scheduler->value, $dispatch->getTrigger());
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus());
        self::assertNotNull($dispatch->getDeliveredAt());
    }

    public function testSchedulerTriggerReturnsErrorWhenAlreadySentForPeriod(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Scheduler,
            $referenceDate,
        ));

        self::assertSame(
            ['monthly_reminder.error.already_sent_for_period'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler, $referenceDate),
        );
    }

    public function testAdminTriggerCanResendDespiteExistingSchedulerDispatch(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Scheduler,
            $referenceDate,
        ));
        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Admin,
            $referenceDate,
        ));

        $dispatchRepository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);
        self::assertTrue($dispatchRepository->existsForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        ));
        self::assertTrue($dispatchRepository->existsForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Admin->value,
        ));
    }

    public function testAdminSendPersistsDispatchLogWithDeliveryStatus(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Admin,
            $referenceDate,
        ));

        $dispatch = self::getContainer()->get(MonthlyReminderDispatchRepository::class)->findForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Admin->value,
        );
        self::assertNotNull($dispatch);
        self::assertSame(MonthlyReminderTrigger::Admin->value, $dispatch->getTrigger());
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus());
        self::assertNotNull($dispatch->getDeliveredAt());
    }

    public function testSchedulerRetryReusesFailedDispatchRecord(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $dispatchRepository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);
        $referenceDate = $this->frozenReferenceDate();

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Scheduler,
            $referenceDate,
        ));

        $dispatch = $dispatchRepository->findForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        );
        self::assertNotNull($dispatch);
        $dispatchId = (int) $dispatch->getId();

        $dispatch->markFailed('SMTP rate limit');
        $dispatchRepository->save($dispatch);

        self::assertSame([], $this->sender->sendForHospital(
            $hospital,
            MonthlyReminderTrigger::Scheduler,
            $referenceDate,
        ));

        $retried = $dispatchRepository->find($dispatchId);
        self::assertNotNull($retried);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $retried->getStatus());
        self::assertNull($retried->getFailureReason());
    }

    public function testReminderGoesOutAsNotificationEmailAndCompletesTheDispatch(): void
    {
        $hospital = $this->createHospital(optedOut: false);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler, $this->frozenReferenceDate()));

        self::assertEmailCount(1);
        $email = $this->findReminderEmail();
        $dispatch = self::getContainer()->get(MonthlyReminderDispatchRepository::class)
            ->findForHospitalPeriodAndTrigger((int) $hospital->getId(), '2026-06', MonthlyReminderTrigger::Scheduler->value);
        self::assertNotNull($dispatch);
        self::assertSame((string) $dispatch->getId(), $email->getHeaders()->get(MonthlyReminderEmailCustomizer::DISPATCH_ID_HEADER)?->getBodyAsString());
        self::assertNotNull($email->getHeaders()->get(NotificationEmailFactory::DELIVERY_ID_HEADER));
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus(), 'the mail events complete the dispatch');

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT type, in_app_visible, scope_reference_id, parameters FROM notification WHERE recipient_id = ?',
            [$hospital->getOwner()?->getId()],
        );
        self::assertIsArray($row);
        self::assertSame(['engagement.monthly_reminder', false, $hospital->getId()], [$row['type'], (bool) $row['in_app_visible'], $row['scope_reference_id']]);
        $parameters = json_decode((string) $row['parameters'], true);
        self::assertIsArray($parameters);
        self::assertSame(['2026-07-01', $dispatch->getId()], [$parameters['referenceDate'], $parameters['dispatchId']]);
        self::assertNotNull($dispatch->getAttemptId());
        self::assertSame($dispatch->getAttemptId(), $parameters['attemptId'], 'the notification names the send attempt');
    }

    public function testSendUsesOwnerGermanLocale(): void
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('de-owner-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'locale' => 'de',
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

        self::assertEmailCount(1);
        $email = $this->findReminderEmail();
        self::assertSame('de', $email->getLocale());
        self::assertEmailSubjectContains($email, 'Monatsübersicht');
    }

    public function testSendUsesOwnerEnglishLocaleWhenExplicit(): void
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('en-owner-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'locale' => 'en',
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

        self::assertEmailCount(1);
        $email = $this->findReminderEmail();
        self::assertSame('en', $email->getLocale());
        self::assertEmailSubjectContains($email, 'Monthly overview');
        self::assertEmailSubjectNotContains($email, 'Monatsübersicht');
    }

    public function testRepeatedAdminActionSendsOneEmailAndANewActionSendsAnother(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $connection = self::getContainer()->get(Connection::class);
        $releaseGuard = static fn () => $connection->executeStatement("UPDATE monthly_reminder_dispatch SET sent_at = sent_at - INTERVAL '10 minutes'");

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin, requestId: 'b3c4a1f0-0000-4000-8000-000000000001'));
        $releaseGuard();
        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin, requestId: 'b3c4a1f0-0000-4000-8000-000000000001'));
        self::assertEmailCount(1, message: 'the same admin action (double click, retry) sends nothing new');

        $releaseGuard();
        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin, requestId: 'b3c4a1f0-0000-4000-8000-000000000002'));
        self::assertEmailCount(2);
        self::assertSame(['engagement.monthly_reminder', 'engagement.monthly_reminder'], $connection->fetchFirstColumn('SELECT type FROM notification ORDER BY id'), 'admin sends use the regular reminder type');
    }

    public function testOwnersWhoOnlyKeepTheInAppEntryGetNoEmail(): void
    {
        $owner = UserFactory::createOne(['email' => sprintf('inbox-%s@example.test', bin2hex(random_bytes(4))), 'isVerified' => true]);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled) VALUES (?, 'type', 'engagement.monthly_reminder', 'email', false), (?, 'type', 'engagement.monthly_reminder', 'in_app', true)",
            [$owner->getId(), $owner->getId()],
        );
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler, $this->frozenReferenceDate()));

        self::assertEmailCount(0);
        self::assertSame(['engagement.monthly_reminder' => true], array_column(
            $connection->fetchAllAssociative('SELECT type, in_app_visible FROM notification WHERE recipient_id = ?', [$owner->getId()]),
            'in_app_visible',
            'type',
        ));
        $dispatch = self::getContainer()->get(MonthlyReminderDispatchRepository::class)
            ->findForHospitalPeriodAndTrigger((int) $hospital->getId(), '2026-06', MonthlyReminderTrigger::Scheduler->value);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch?->getStatus(), 'nothing else would complete the dispatch');
    }

    public function testCliSendsAreAlwaysNewPublications(): void
    {
        $hospital = $this->createHospital(optedOut: false);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Cli, $this->frozenReferenceDate()));
        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Cli, $this->frozenReferenceDate()));

        self::assertEmailCount(2);
    }

    private function frozenReferenceDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    private function findReminderEmail(): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail) {
                return $message;
            }
        }

        self::fail('Expected monthly reminder TemplatedEmail to be queued.');
    }

    private function createHospital(
        bool $optedOut,
        bool $participating = true,
        mixed $owner = 'default',
    ): Hospital {
        if ('default' === $owner) {
            $owner = UserFactory::createOne([
                'email' => sprintf('reminder-%s@example.test', bin2hex(random_bytes(4))),
                'isVerified' => true,
            ]);
            if ($optedOut) {
                $this->optOut($owner);
            }
        }

        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);

        return HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => $participating,
        ]);
    }

    private function optOut(object $owner): void
    {
        \assert($owner instanceof \App\User\Domain\Entity\User);
        new \App\Tests\Support\Notification\NotificationPreferenceTestState(self::getContainer()->get(Connection::class))
            ->switchOff($owner, \App\Engagement\Application\MonthlyReminderNotificationType::TYPE);
    }
}
