<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Functional\Command;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\UI\Console\Command\MonthlyReminderPreviewCommand;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MonthlyReminderPreviewCommandTest extends DatabaseKernelTestCase
{
    public function testRequiresHospitalOption(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('Option --hospital is required', $tester->getDisplay());
    }

    public function testFailsWhenHospitalIsNotFound(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::FAILURE, $tester->execute(['--hospital' => '999999']));
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testWritesHtmlPreviewToStdout(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital' => (string) $hospital->getId(),
            '--date' => '2026-06-17',
        ]));
        self::assertStringContainsString('<!DOCTYPE html>', $tester->getDisplay());
    }

    public function testSendFailsForOptedOutOwnerAndShowsHint(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--hospital' => (string) $hospital->getId(),
            '--send' => true,
            '--date' => '2026-06-17',
        ]));
        self::assertStringContainsString('Use --ignore-opt-out', $tester->getDisplay());
    }

    public function testSendSucceedsWithIgnoreOptOut(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital' => (string) $hospital->getId(),
            '--send' => true,
            '--ignore-opt-out' => true,
            '--date' => '2026-06-17',
        ]));
        self::assertStringContainsString('Sent reminder to', $tester->getDisplay());
    }

    public function testWritesHtmlPreviewToFile(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $outputPath = sys_get_temp_dir().'/monthly-reminder-preview-'.bin2hex(random_bytes(4)).'.html';
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        try {
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--hospital' => (string) $hospital->getId(),
                '--output' => $outputPath,
                '--date' => '2026-06-17',
            ]));
            self::assertStringContainsString('Wrote preview to', $tester->getDisplay());
            self::assertStringContainsString('<!DOCTYPE html>', (string) file_get_contents($outputPath));
        } finally {
            @unlink($outputPath);
        }
    }

    private function createHospital(bool $optedOut): object
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('preview-%s@example.test', bin2hex(random_bytes(4))),
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
