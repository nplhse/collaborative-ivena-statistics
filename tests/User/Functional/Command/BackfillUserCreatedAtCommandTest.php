<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\UI\Console\Command\BackfillUserCreatedAtCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillUserCreatedAtCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testOwnImportUpdatesCreatedAtAndIsIdempotent(): void
    {
        $user = $this->createUser('max-mustermann', '2026-08-13 14:20:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Own Import Klinik',
            'owner' => $user,
            'createdBy' => $user,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-05-10 09:42:00'),
        ]);

        $userId = $this->userId($user);
        $updatedAtBefore = $this->fetchUpdatedAt($userId);

        $tester = $this->createCommandTester();
        $dryRun = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $dryRun);
        $display = $tester->getDisplay();
        self::assertStringContainsString('User #'.$userId.' – max-mustermann', $display);
        self::assertStringContainsString('2026-08-13 14:20', $display);
        self::assertStringContainsString('own import', $display);
        self::assertStringContainsString('2022-05-10 09:42', $display);
        self::assertStringContainsString('would update', $display);
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($userId)->format('Y-m-d H:i:s'));

        $apply = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $apply);
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($userId)->format('Y-m-d H:i:s'));
        self::assertSame($updatedAtBefore, $this->fetchUpdatedAt($userId));

        $second = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $second);
        self::assertStringContainsString('no change – current value is already earlier', $tester->getDisplay());
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($userId)->format('Y-m-d H:i:s'));
        self::assertSame($updatedAtBefore, $this->fetchUpdatedAt($userId));
    }

    public function testHospitalOwnerImportUsedWhenNoOwnImport(): void
    {
        $user = $this->createUser('max-mustermann', '2026-08-13 14:20:00');
        $importer = $this->createUser('legacy-importer', '2020-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Musterstadt',
            'owner' => $user,
            'createdBy' => $importer,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $importer,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2021-04-03 08:15:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('hospital import', $display);
        self::assertStringContainsString('Klinikum Musterstadt', $display);
        self::assertStringContainsString('2021-04-03 08:15', $display);
        self::assertSame('2021-04-03 08:15:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testAccessGrantHospitalImportIsIgnored(): void
    {
        $user = $this->createUser('erika-beispiel', '2026-08-13 14:20:00');
        $owner = $this->createUser('hospital-owner', '2020-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Grant Klinik',
            'owner' => $owner,
            'createdBy' => $owner,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $user,
            'hospital' => $hospital,
            'createdBy' => $owner,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2021-09-03 11:14:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $block = $this->userBlock($tester->getDisplay(), 'erika-beispiel');
        self::assertStringContainsString('Own import:', $block);
        self::assertStringContainsString('none', $block);
        self::assertStringContainsString('Hospital import:', $block);
        self::assertStringContainsString('no change', $block);
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testAccessGrantUserStillUsesOwnImport(): void
    {
        $user = $this->createUser('grant-with-own-import', '2026-08-13 14:20:00');
        $owner = $this->createUser('grant-hospital-owner', '2020-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Grant Own Import Klinik',
            'owner' => $owner,
            'createdBy' => $owner,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $user,
            'hospital' => $hospital,
            'createdBy' => $owner,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-05-10 09:42:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $block = $this->userBlock($tester->getDisplay(), 'grant-with-own-import');
        self::assertStringContainsString('own import', $block);
        self::assertStringNotContainsString('hospital import', $block);
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testGrantHospitalIsIgnoredWhenUserAlsoOwnsAnotherHospital(): void
    {
        $user = $this->createUser('owner-and-grantee', '2026-08-13 14:20:00');
        $otherOwner = $this->createUser('other-hospital-owner', '2019-01-01 00:00:00');
        $ownedHospital = HospitalFactory::createOne([
            'name' => 'Owned Klinik',
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $grantedHospital = HospitalFactory::createOne([
            'name' => 'Older Grant Klinik',
            'owner' => $otherOwner,
            'createdBy' => $otherOwner,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $user,
            'hospital' => $grantedHospital,
            'createdBy' => $otherOwner,
        ]);
        ImportFactory::createOne([
            'hospital' => $ownedHospital,
            'createdBy' => $otherOwner,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2023-01-01 10:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $grantedHospital,
            'createdBy' => $otherOwner,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2020-08-15 07:30:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $block = $this->userBlock($tester->getDisplay(), 'owner-and-grantee');
        self::assertStringContainsString('hospital import', $block);
        self::assertStringContainsString('Owned Klinik', $block);
        self::assertStringNotContainsString('Older Grant Klinik', $block);
        self::assertSame('2023-01-01 10:00:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testEarliestHospitalImportWinsAcrossMultipleHospitals(): void
    {
        $user = $this->createUser('multi-hospital', '2026-08-13 14:20:00');
        $importer = $this->createUser('multi-importer', '2019-01-01 00:00:00');
        $hospitalA = HospitalFactory::createOne([
            'name' => 'Hospital A',
            'owner' => $user,
            'createdBy' => $importer,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'Hospital B',
            'owner' => $user,
            'createdBy' => $importer,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospitalA,
            'createdBy' => $importer,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2023-01-01 10:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospitalB,
            'createdBy' => $importer,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2020-08-15 07:30:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Hospital B', $tester->getDisplay());
        self::assertSame('2020-08-15 07:30:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testOwnImportTakesPrecedenceOverOlderHospitalImport(): void
    {
        $user = $this->createUser('priority-user', '2026-08-13 14:20:00');
        $importer = $this->createUser('priority-importer', '2019-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Priority Klinik',
            'owner' => $user,
            'createdBy' => $importer,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-05-10 09:42:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $importer,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('own import', $display);
        self::assertStringNotContainsString('hospital import', $this->userBlock($display, 'priority-user'));
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testNoHistoricalDataLeavesCreatedAtUnchanged(): void
    {
        $user = $this->createUser('no-history', '2026-08-13 14:20:00');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Own import:', $display);
        self::assertStringContainsString('none', $display);
        self::assertStringContainsString('Hospital import:', $display);
        self::assertStringContainsString('no change', $this->userBlock($display, 'no-history'));
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testCurrentCreatedAtAlreadyEarlierIsNotReplaced(): void
    {
        $user = $this->createUser('already-earlier', '2021-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Later Import Klinik',
            'owner' => $user,
            'createdBy' => $user,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-05-10 09:42:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('no change – current value is already earlier', $tester->getDisplay());
        self::assertSame('2021-01-01 00:00:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testFailedAndPendingImportsAreIgnoredAndPartialCounts(): void
    {
        $user = $this->createUser('status-user', '2026-08-13 14:20:00');
        $importer = $this->createUser('status-importer', '2018-01-01 00:00:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Status Klinik',
            'owner' => $user,
            'createdBy' => $importer,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::FAILED,
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::PENDING,
            'createdAt' => new \DateTimeImmutable('2020-06-01 00:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::PARTIAL,
            'createdAt' => new \DateTimeImmutable('2022-05-10 09:42:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $importer,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2019-01-01 00:00:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $block = $this->userBlock($tester->getDisplay(), 'status-user');
        self::assertStringContainsString('own import', $block);
        self::assertStringContainsString('2022-05-10 09:42', $block);
        self::assertSame('2022-05-10 09:42:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    public function testExplicitDryRunFlagDoesNotWrite(): void
    {
        $user = $this->createUser('dry-run-flag', '2026-08-13 14:20:00');
        $hospital = HospitalFactory::createOne([
            'name' => 'Dry Run Klinik',
            'owner' => $user,
            'createdBy' => $user,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 09:42:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('would update', $tester->getDisplay());
        self::assertSame('2026-08-13 14:20:00', $this->fetchCreatedAt($this->userId($user))->format('Y-m-d H:i:s'));
    }

    private function createUser(string $username, string $createdAt): User
    {
        return UserFactory::createOne([
            'username' => $username,
            'email' => $username.'@example.test',
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function userId(User $user): int
    {
        $id = $user->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function fetchCreatedAt(int $userId): \DateTimeImmutable
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $value = $connection->fetchOne('SELECT created_at FROM "user" WHERE id = :id', ['id' => $userId]);
        self::assertNotFalse($value);
        self::assertNotNull($value);

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value);
    }

    private function fetchUpdatedAt(int $userId): mixed
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchOne('SELECT updated_at FROM "user" WHERE id = :id', ['id' => $userId]);
    }

    private function userBlock(string $display, string $username): string
    {
        if (1 !== preg_match('/User #\d+ – '.preg_quote($username, '/').'\n.*?(?=User #\d+ – |\nSummary\n|\n \[OK\])/s', $display, $matches)) {
            self::fail('Could not find report block for user '.$username);
        }

        return $matches[0];
    }

    private function createCommandTester(): CommandTester
    {
        /** @var BackfillUserCreatedAtCommand $command */
        $command = self::getContainer()->get(BackfillUserCreatedAtCommand::class);

        return new CommandTester($command);
    }
}
