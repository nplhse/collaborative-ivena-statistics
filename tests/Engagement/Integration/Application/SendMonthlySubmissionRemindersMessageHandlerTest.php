<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Engagement\Application\MessageHandler\SendMonthlySubmissionRemindersMessageHandler;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Lock\LockFactory;

final class SendMonthlySubmissionRemindersMessageHandlerTest extends DatabaseKernelTestCase
{
    use MailerAssertionsTrait;

    public function testHandlerSkipsWhenBatchLockIsAlreadyHeld(): void
    {
        self::bootKernel();
        $lock = self::getContainer()->get(LockFactory::class)->createLock('monthly-submission-reminder');
        self::assertTrue($lock->acquire());

        try {
            $handler = self::getContainer()->get(SendMonthlySubmissionRemindersMessageHandler::class);
            $handler(new SendMonthlySubmissionRemindersMessage());
            self::assertTrue($lock->isAcquired());
        } finally {
            $lock->release();
        }

        self::assertEmailCount(0);
    }

    public function testHandlerProcessesParticipatingHospitals(): void
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
        HospitalFactory::createOne([
            'owner' => $optedOutOwner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
        ]);
        HospitalFactory::createOne([
            'owner' => $eligibleOwner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
        ]);

        $hospitalRepository = self::getContainer()->get(HospitalRepository::class);
        self::assertCount(2, $hospitalRepository->findParticipatingWithOwner());

        $handler = self::getContainer()->get(SendMonthlySubmissionRemindersMessageHandler::class);
        $handler(new SendMonthlySubmissionRemindersMessage());

        $batchLock = self::getContainer()->get(LockFactory::class)->createLock('monthly-submission-reminder');
        self::assertTrue($batchLock->acquire());
        $batchLock->release();
    }
}
