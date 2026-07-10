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
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

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

    public function testAdminTriggerSendsDespiteOptOut(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        $errors = $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin);

        self::assertSame([], $errors);
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
            'receivesMonthlySubmissionReminder' => true,
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
            'receivesMonthlySubmissionReminder' => true,
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame(
            ['monthly_reminder.error.email_not_verified'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testAdminTriggerReturnsErrorWhenManualLockIsAlreadyHeld(): void
    {
        $hospital = $this->createHospital(optedOut: true);
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

    public function testSchedulerSendPersistsDispatchLogForReportingPeriod(): void
    {
        $hospital = $this->createHospital(optedOut: false);
        $referenceDate = new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin'));

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

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler));

        self::assertSame(
            ['monthly_reminder.error.already_sent_for_period'],
            $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler),
        );
    }

    public function testAdminTriggerCanResendDespiteExistingSchedulerDispatch(): void
    {
        $hospital = $this->createHospital(optedOut: false);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler));
        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

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

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

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

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler));

        $dispatch = $dispatchRepository->findForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        );
        self::assertNotNull($dispatch);
        $dispatchId = (int) $dispatch->getId();

        $dispatch->markFailed('SMTP rate limit');
        $dispatchRepository->save($dispatch);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler));

        $retried = $dispatchRepository->find($dispatchId);
        self::assertNotNull($retried);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $retried->getStatus());
        self::assertNull($retried->getFailureReason());
    }

    public function testMarksDispatchFailedWhenMailerThrows(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $hospital = $this->createHospital(optedOut: false);
        $dispatchRepository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);

        self::getContainer()->set(MailerInterface::class, new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('SMTP unavailable');
            }
        });

        $sender = self::getContainer()->get(MonthlyReminderSender::class);

        try {
            $sender->sendForHospital($hospital, MonthlyReminderTrigger::Scheduler);
            self::fail('Expected mailer exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('SMTP unavailable', $exception->getMessage());
        }

        $dispatch = $dispatchRepository->findForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        );
        self::assertNotNull($dispatch);
        self::assertSame(MonthlyReminderDispatchStatus::Failed, $dispatch->getStatus());
        self::assertSame('SMTP unavailable', $dispatch->getFailureReason());
    }

    public function testSendUsesOwnerGermanLocale(): void
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('de-owner-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
            'locale' => 'de',
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

        self::assertQueuedEmailCount(1);
        $email = $this->findReminderEmail();
        self::assertSame('de', $email->getLocale());
        self::assertEmailSubjectContains($email, 'Monatsübersicht');
    }

    public function testSendUsesOwnerEnglishLocaleWhenExplicit(): void
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('en-owner-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
            'locale' => 'en',
        ]);
        $hospital = $this->createHospital(optedOut: false, owner: $owner);

        self::assertSame([], $this->sender->sendForHospital($hospital, MonthlyReminderTrigger::Admin));

        self::assertQueuedEmailCount(1);
        $email = $this->findReminderEmail();
        self::assertSame('en', $email->getLocale());
        self::assertEmailSubjectContains($email, 'Monthly overview');
        self::assertEmailSubjectNotContains($email, 'Monatsübersicht');
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
                'receivesMonthlySubmissionReminder' => !$optedOut,
            ]);
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
}
