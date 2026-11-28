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
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Dry run and apply of `app:user-activity:backfill` over the console. What the backfill derives, that a second run
 * writes nothing and skips rows the live recorder wrote, is in `UserActivityBackfillTest`.
 */
#[ResetDatabase]
final class BackfillUserActivityCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDefaultDryRunWritesNothing(): void
    {
        $alice = $this->createUserWithImport();
        $tester = $this->createCommandTester();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Dry run: no rows will be written.', $tester->getDisplay());
        self::assertStringContainsString('Rows would be written', $tester->getDisplay());
        self::assertSame([], $this->activityTypes($alice));
    }

    public function testApplyWritesTheActivity(): void
    {
        $alice = $this->createUserWithImport();
        $tester = $this->createCommandTester();

        $tester->execute(['--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Apply mode', $tester->getDisplay());
        self::assertStringContainsString('Rows written', $tester->getDisplay());
        $types = $this->activityTypes($alice);
        self::assertContains(UserActivityType::JOINED, $types);
        self::assertContains(UserActivityType::FIRST_IMPORT, $types);
        self::assertContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $types);
    }

    public function testApplyTakesPrecedenceOverDryRun(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $tester = $this->createCommandTester();

        $tester->execute(['--apply' => true, '--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Both --apply and --dry-run were passed', $tester->getDisplay());
        self::assertContains(UserActivityType::JOINED, $this->activityTypes($alice));
    }

    private function createUserWithImport(): User
    {
        $alice = UserFactory::createOne([
            'username' => 'alice',
            'createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00'),
        ]);
        ImportFactory::createOne([
            'createdBy' => $alice,
            'hospital' => HospitalFactory::createOne(['name' => 'Command Klinik', 'owner' => $alice]),
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00'),
        ]);

        return $alice;
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
