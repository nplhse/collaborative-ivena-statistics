<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderSender;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Lock\LockFactory;

final class MonthlyReminderSenderTest extends DatabaseKernelTestCase
{
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
