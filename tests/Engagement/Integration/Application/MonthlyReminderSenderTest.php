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
use Zenstruck\Foundry\Persistence\Proxy;

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

        $errors = $this->sender->sendForHospital($hospital->_real(), MonthlyReminderTrigger::Scheduler);

        self::assertSame(['monthly_reminder.error.opted_out'], $errors);
    }

    public function testCliTriggerSkipsWhenUserOptedOut(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        $errors = $this->sender->sendForHospital($hospital->_real(), MonthlyReminderTrigger::Cli);

        self::assertSame(['monthly_reminder.error.opted_out'], $errors);
    }

    public function testAdminTriggerSendsDespiteOptOut(): void
    {
        $hospital = $this->createHospital(optedOut: true);

        $errors = $this->sender->sendForHospital($hospital->_real(), MonthlyReminderTrigger::Admin);

        self::assertSame([], $errors);
    }

    /**
     * @return Proxy<Hospital>
     */
    private function createHospital(bool $optedOut): Proxy
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('reminder-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => !$optedOut,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);

        return HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
    }
}
