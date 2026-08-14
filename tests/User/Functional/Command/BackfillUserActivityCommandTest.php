<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserActivityRepository;
use App\User\UI\Console\Command\BackfillUserActivityCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillUserActivityCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDefaultDryRunDoesNotWriteAndApplyIsIdempotent(): void
    {
        $user = UserFactory::createOne([
            'username' => 'activity-backfill',
            'createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00'),
        ]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Command Klinik',
            'owner' => $user,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00'),
        ]);

        $tester = $this->createCommandTester();
        $dryRun = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $dryRun);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertStringContainsString('Rows would be written', $display);
        self::assertSame([], $this->activityTypes($user));

        $apply = $tester->execute(['--apply' => true]);
        self::assertSame(Command::SUCCESS, $apply);
        self::assertStringContainsString('Apply mode', $tester->getDisplay());
        self::assertStringContainsString('Rows written', $tester->getDisplay());

        $types = $this->activityTypes($user);
        self::assertContains(UserActivityType::JOINED, $types);
        self::assertContains(UserActivityType::FIRST_IMPORT, $types);
        self::assertContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $types);

        $second = $tester->execute(['--apply' => true]);
        self::assertSame(Command::SUCCESS, $second);
        self::assertSame($types, $this->activityTypes($user));
        self::assertStringContainsString('Skipped existing', $tester->getDisplay());
    }

    public function testApplyTakesPrecedenceOverDryRun(): void
    {
        $user = UserFactory::createOne(['username' => 'both-flags']);
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--apply' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Both --apply and --dry-run were passed', $tester->getDisplay());
        self::assertContains(UserActivityType::JOINED, $this->activityTypes($user));
    }

    /**
     * @return list<UserActivityType>
     */
    private function activityTypes(User $user): array
    {
        return array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user]),
        );
    }

    private function createCommandTester(): CommandTester
    {
        /** @var BackfillUserActivityCommand $command */
        $command = self::getContainer()->get(BackfillUserActivityCommand::class);

        return new CommandTester($command);
    }
}
