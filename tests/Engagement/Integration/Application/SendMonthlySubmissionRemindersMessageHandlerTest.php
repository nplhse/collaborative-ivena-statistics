<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Engagement\Application\MessageHandler\SendMonthlySubmissionRemindersMessageHandler;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Lock\LockFactory;

final class SendMonthlySubmissionRemindersMessageHandlerTest extends DatabaseKernelTestCase
{
    public function testHandlerSkipsWhenBatchLockIsAlreadyHeld(): void
    {
        self::bootKernel();
        $lock = self::getContainer()->get(LockFactory::class)->createLock('monthly-submission-reminder');
        self::assertTrue($lock->acquire());

        try {
            $handler = self::getContainer()->get(SendMonthlySubmissionRemindersMessageHandler::class);
            $handler(new SendMonthlySubmissionRemindersMessage(
                new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin')),
            ));
            self::assertTrue($lock->isAcquired());
        } finally {
            $lock->release();
        }
    }

    public function testHandlerSkipsWhenNotFirstWorkingDay(): void
    {
        self::bootKernel();

        $eligibleOwner = UserFactory::createOne([
            'email' => sprintf('batch-skip-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $eligibleOwner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
        ]);

        $handler = self::getContainer()->get(SendMonthlySubmissionRemindersMessageHandler::class);
        $handler(new SendMonthlySubmissionRemindersMessage(
            new \DateTimeImmutable('2026-06-02 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));

        self::assertFalse(self::getContainer()->get(MonthlyReminderDispatchRepository::class)->existsForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            '2026-05',
            MonthlyReminderTrigger::Scheduler->value,
        ));
    }

    public function testHandlerProcessesParticipatingHospitalsOnFirstWorkingDay(): void
    {
        self::bootKernel();

        $optedOutOwner = UserFactory::createOne([
            'email' => sprintf('batch-skipped-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => false,
        ]);
        $eligibleOwner = UserFactory::createOne([
            'email' => sprintf('batch-sent-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $skippedHospital = HospitalFactory::createOne([
            'owner' => $optedOutOwner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
        ]);
        $eligibleHospital = HospitalFactory::createOne([
            'owner' => $eligibleOwner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
        ]);

        $hospitalRepository = self::getContainer()->get(HospitalRepository::class);
        self::assertCount(2, $hospitalRepository->findParticipatingWithOwner());

        $handler = self::getContainer()->get(SendMonthlySubmissionRemindersMessageHandler::class);
        $handler(new SendMonthlySubmissionRemindersMessage(
            new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));

        $dispatchRepository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);
        self::assertTrue($dispatchRepository->existsForHospitalPeriodAndTrigger(
            (int) $eligibleHospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        ));
        self::assertFalse($dispatchRepository->existsForHospitalPeriodAndTrigger(
            (int) $skippedHospital->getId(),
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
        ));

        $batchLock = self::getContainer()->get(LockFactory::class)->createLock('monthly-submission-reminder');
        self::assertTrue($batchLock->acquire());
        $batchLock->release();
    }
}
