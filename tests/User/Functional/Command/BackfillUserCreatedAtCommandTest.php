<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\UI\Console\Command\BackfillUserCreatedAtCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Dry run and apply of `app:user:backfill-created-at` over the console. Which date wins (own import before hospital
 * import, earliest hospital, already earlier, none) is in `UserCreatedAtBackfillDecisionTest`; that failed and pending
 * imports do not count in `DoctrineUserCreatedAtBackfillImportSourceTest`; that access grants do not count in
 * `UserCreatedAtBackfillHospitalQueryTest`.
 */
#[ResetDatabase]
final class BackfillUserCreatedAtCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    /** @return iterable<string, array{array<string, bool>}> */
    public static function dryRunOptions(): iterable
    {
        yield 'default' => [[]];
        yield 'explicit --dry-run' => [['--dry-run' => true]];
    }

    /**
     * @param array<string, bool> $options
     */
    #[DataProvider('dryRunOptions')]
    public function testDryRunReportsEveryUserAndWritesNothing(array $options): void
    {
        $alice = $this->createUser('alice', '2026-08-13 14:20:00');
        $bob = $this->createUser('bob', '2026-08-13 14:20:00');
        // carol imports for bob's hospital; her own account date is older than that import
        $carol = $this->createUser('carol', '2020-01-01 00:00:00');
        $dave = $this->createUser('dave', '2026-08-13 14:20:00');
        $this->import($alice, HospitalFactory::createOne(['name' => 'Alice Klinik', 'owner' => $alice]), '2022-05-10 09:42:00');
        $this->import($carol, HospitalFactory::createOne(['name' => 'Klinikum Musterstadt', 'owner' => $bob]), '2021-04-03 08:15:00');
        $tester = $this->createCommandTester();

        $tester->execute($options);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertStringContainsString(
            $this->block($alice, '2026-08-13 14:20', "Source:\nown import\nReconstructed createdAt:\n2022-05-10 09:42\nAction:\nwould update"),
            $display,
        );
        self::assertStringContainsString(
            $this->block($bob, '2026-08-13 14:20', "Source:\nhospital import\nHospital:\nKlinikum Musterstadt\nReconstructed createdAt:\n2021-04-03 08:15\nAction:\nwould update"),
            $display,
        );
        self::assertStringContainsString(
            $this->block($carol, '2020-01-01 00:00', "Source:\nown import\nReconstructed createdAt:\n2021-04-03 08:15\nAction:\nno change – current value is already earlier"),
            $display,
        );
        self::assertStringContainsString(
            $this->block($dave, '2026-08-13 14:20', "Own import:\nnone\nHospital import:\nnone\nAction:\nno change"),
            $display,
        );
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($alice));
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($bob));
    }

    public function testApplyWritesTheEarlierDateWithoutTouchingUpdatedAt(): void
    {
        $alice = $this->createUser('alice', '2026-08-13 14:20:00');
        $bob = $this->createUser('bob', '2026-08-13 14:20:00');
        $carol = $this->createUser('carol', '2020-01-01 00:00:00');
        $this->import($alice, HospitalFactory::createOne(['name' => 'Alice Klinik', 'owner' => $alice]), '2022-05-10 09:42:00');
        $this->import($carol, HospitalFactory::createOne(['name' => 'Klinikum Musterstadt', 'owner' => $bob]), '2021-04-03 08:15:00');
        $updatedAtBefore = $this->fetchUpdatedAt($alice);
        $tester = $this->createCommandTester();

        $tester->execute(['--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Updated 2 user(s).', $tester->getDisplay(true));
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($alice), 'own import');
        self::assertSame('2021-04-03 08:15:00', $this->fetchCreatedAt($bob), 'import of the owned hospital');
        self::assertSame('2020-01-01 00:00:00', $this->fetchCreatedAt($carol), 'already earlier');
        self::assertSame($updatedAtBefore, $this->fetchUpdatedAt($alice), 'the backfill is no profile change');
    }

    private function createUser(string $username, string $createdAt): User
    {
        return UserFactory::createOne([
            'username' => $username,
            'email' => $username.'@example.test',
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function import(User $createdBy, Hospital $hospital, string $createdAt): void
    {
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function block(User $user, string $currentCreatedAt, string $rest): string
    {
        return \sprintf("User #%d – %s\nCurrent createdAt:\n%s\n%s\n", (int) $user->getId(), (string) $user->getUsername(), $currentCreatedAt, $rest);
    }

    private function fetchCreatedAt(User $user): string
    {
        $value = self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT created_at FROM "user" WHERE id = :id', ['id' => $user->getId()]);
        self::assertIsString($value);

        return $value;
    }

    private function fetchUpdatedAt(User $user): mixed
    {
        return self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT updated_at FROM "user" WHERE id = :id', ['id' => $user->getId()]);
    }

    private function createCommandTester(): CommandTester
    {
        /** @var BackfillUserCreatedAtCommand $command */
        $command = self::getContainer()->get(BackfillUserCreatedAtCommand::class);

        return new CommandTester($command);
    }
}
