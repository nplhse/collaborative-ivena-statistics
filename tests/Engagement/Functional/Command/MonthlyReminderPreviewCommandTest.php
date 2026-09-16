<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Functional\Command;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\UI\Console\Command\MonthlyReminderPreviewCommand;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
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
        self::assertStringContainsString('Option --hospital-id is required', $tester->getDisplay());
    }

    public function testFailsWhenHospitalIsNotFound(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::FAILURE, $tester->execute(['--hospital-id' => '999999']));
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testWritesHtmlPreviewToStdout(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital-id' => (string) $hospital->getId(),
            '--date' => '2026-06-17',
        ]));
        self::assertStringContainsString('<!DOCTYPE html>', $tester->getDisplay());
    }

    public function testPreviewRendersGermanTemplateForGermanOwner(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne([
            'email' => sprintf('preview-de-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
            'locale' => 'de',
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Deutsches Testkrankenhaus',
        ]);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital-id' => (string) $hospital->getId(),
            '--date' => '2026-06-17',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Monatsübersicht: Deutsches Testkrankenhaus', $output);
        self::assertStringContainsString('Mai 2026', $output);
        self::assertStringContainsString('/statistics/reports/monthly', $output);
        self::assertStringNotContainsString('data-testid="monthly-reminder-closed-department"', $output);
        self::assertStringContainsString('Plattform-Trends', $output);
    }

    public function testPersonalizedPreviewPlacesClosedDepartmentInClinicalProfile(): void
    {
        self::bootKernel();
        $hospital = $this->createPersonalizedGermanHospital();
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital-id' => (string) $hospital->getId(),
            '--date' => '2026-06-17',
        ]));

        $output = $tester->getDisplay();
        $profilePos = strpos($output, 'Klinisches Profil');
        $closedDepartmentPos = strpos($output, 'data-testid="monthly-reminder-closed-department"');
        $trendPos = strpos($output, 'Zuweisungstrend');
        $ctaPos = strpos($output, 'Monatsbericht ansehen');

        self::assertNotFalse($profilePos);
        self::assertNotFalse($closedDepartmentPos);
        self::assertNotFalse($trendPos);
        self::assertNotFalse($ctaPos);
        self::assertLessThan($trendPos, $ctaPos);
        self::assertLessThan($profilePos, $trendPos);
        self::assertLessThan($closedDepartmentPos, $profilePos);
        self::assertStringContainsString('Notzuweisungen', $output);
        self::assertStringContainsString('4 Zuweisungen', $output);
        self::assertStringContainsString('20,0%', $output);
        self::assertStringContainsString('width="20%"', $output);
        self::assertStringContainsString('+100,0%', $output);
        self::assertStringContainsString('zum Vormonat', $output);
        self::assertStringContainsString('Im Monatsbericht ansehen', $output);
        self::assertStringContainsString('/statistics/reports/monthly', $output);
        self::assertStringNotContainsString('Keine in diesem Monat.', $output);
        self::assertStringNotContainsString('trotz Abmeldung', $output);
    }

    public function testPersonalizedPreviewShowsEmptyClosedDepartmentState(): void
    {
        self::bootKernel();
        $hospital = $this->createPersonalizedGermanHospital(withClosedAssignments: false);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--hospital-id' => (string) $hospital->getId(),
            '--date' => '2026-06-17',
        ]));

        $output = $tester->getDisplay();
        $profilePos = strpos($output, 'Klinisches Profil');
        $closedDepartmentPos = strpos($output, 'data-testid="monthly-reminder-closed-department"');

        self::assertNotFalse($profilePos);
        self::assertNotFalse($closedDepartmentPos);
        self::assertLessThan($closedDepartmentPos, $profilePos);
        self::assertStringContainsString('Notzuweisungen', $output);
        self::assertStringContainsString('Keine in diesem Monat.', $output);
        self::assertStringNotContainsString('4 Zuweisungen', $output);
        self::assertStringNotContainsString('width="2%"', $output);
        self::assertStringContainsString('Im Monatsbericht ansehen', $output);
    }

    public function testSendFailsForOptedOutOwnerAndShowsHint(): void
    {
        self::bootKernel();
        $hospital = $this->createHospital(optedOut: true);
        $tester = new CommandTester(self::getContainer()->get(MonthlyReminderPreviewCommand::class));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--hospital-id' => (string) $hospital->getId(),
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
            '--hospital-id' => (string) $hospital->getId(),
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
                '--hospital-id' => (string) $hospital->getId(),
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

    private function createPersonalizedGermanHospital(bool $withClosedAssignments = true): object
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('preview-de-personalized-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
            'receivesMonthlySubmissionReminder' => true,
            'locale' => 'de',
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Deutsches Testkrankenhaus',
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'createdAt' => new \DateTimeImmutable('2026-05-10'),
            'status' => ImportStatus::COMPLETED,
        ]);

        if (!$withClosedAssignments) {
            return $hospital;
        }

        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        $raw = IndicationRawFactory::createOne();
        $normalized = IndicationNormalizedFactory::createOne();

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'departmentWasClosed' => true,
            'createdAt' => new \DateTimeImmutable('2026-04-15'),
        ]);
        AllocationFactory::createMany(16, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'departmentWasClosed' => false,
            'createdAt' => new \DateTimeImmutable('2026-04-16'),
        ]);
        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'departmentWasClosed' => true,
            'createdAt' => new \DateTimeImmutable('2026-03-15'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport((int) $import->getId());

        return $hospital;
    }
}
