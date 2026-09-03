<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\UI\Console\Command\BackfillHospitalParticipatingSinceCommand;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillHospitalParticipatingSinceCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunDoesNotWriteAndApplyFillsFromAudit(): void
    {
        $hospital = $this->createParticipatingHospitalWithoutSince('Audit Klinik');
        $hospitalId = $this->hospitalId($hospital);
        $this->insertHospitalAudit(
            $hospitalId,
            'update',
            ['isParticipating' => ['old' => false, 'new' => true]],
            new \DateTimeImmutable('2026-04-02 11:30:00'),
        );

        $tester = $this->createCommandTester();
        $dryRun = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $dryRun);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Hospital #'.$hospitalId.' – Audit Klinik', $display);
        self::assertStringContainsString('audit_log', $display);
        self::assertStringContainsString('2026-04-02 11:30', $display);
        self::assertStringContainsString('would update', $display);
        self::assertNull($this->fetchParticipatingSince($hospitalId));

        $apply = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $apply);
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame('2026-04-02 11:30:00', $this->fetchParticipatingSince($hospitalId)?->format('Y-m-d H:i:s'));
    }

    public function testAuditTakesPrecedenceOverFirstImport(): void
    {
        $user = UserFactory::createOne(['username' => 'backfill-import-user']);
        $hospital = $this->createParticipatingHospitalWithoutSince('Priority Klinik');
        $hospitalId = $this->hospitalId($hospital);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2021-01-01 08:00:00'),
        ]);
        $this->insertHospitalAudit(
            $hospitalId,
            'create',
            ['isParticipating' => ['old' => null, 'new' => true]],
            new \DateTimeImmutable('2026-05-10 09:15:00'),
        );

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $block = $this->hospitalBlock($tester->getDisplay(), 'Priority Klinik');
        self::assertStringContainsString('audit_log', $block);
        self::assertStringNotContainsString('first successful import', $block);
        self::assertSame('2026-05-10 09:15:00', $this->fetchParticipatingSince($hospitalId)?->format('Y-m-d H:i:s'));
    }

    public function testFirstSuccessfulImportIsUsedWhenAuditIsMissing(): void
    {
        $user = UserFactory::createOne(['username' => 'backfill-only-import']);
        $hospital = $this->createParticipatingHospitalWithoutSince('Import Klinik');
        $hospitalId = $this->hospitalId($hospital);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::FAILED,
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::PARTIAL,
            'createdAt' => new \DateTimeImmutable('2022-09-03 14:45:00'),
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('first successful import', $tester->getDisplay());
        self::assertSame('2022-09-03 14:45:00', $this->fetchParticipatingSince($hospitalId)?->format('Y-m-d H:i:s'));
    }

    public function testNoHistoricalDataLeavesParticipatingSinceNull(): void
    {
        $hospital = $this->createParticipatingHospitalWithoutSince('Empty Klinik');
        $hospitalId = $this->hospitalId($hospital);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('no change', $tester->getDisplay());
        self::assertNull($this->fetchParticipatingSince($hospitalId));
    }

    public function testAlreadyFilledAndNonParticipatingHospitalsAreSkipped(): void
    {
        $filled = HospitalFactory::createOne([
            'name' => 'Already Filled',
            'isParticipating' => true,
            'createdAt' => new \DateTimeImmutable('2024-01-01 00:00:00'),
            'participatingSince' => new \DateTimeImmutable('2024-02-01 00:00:00'),
        ]);
        $nonParticipating = HospitalFactory::createOne([
            'name' => 'Not Participating',
            'isParticipating' => false,
        ]);
        $this->clearParticipatingSince($this->hospitalId($nonParticipating));

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('Already Filled', $display);
        self::assertStringNotContainsString('Not Participating', $display);
        self::assertSame('2024-02-01 00:00:00', $this->fetchParticipatingSince($this->hospitalId($filled))?->format('Y-m-d H:i:s'));
        self::assertNull($this->fetchParticipatingSince($this->hospitalId($nonParticipating)));
    }

    private function createParticipatingHospitalWithoutSince(string $name): Hospital
    {
        /** @var AuditContext $auditContext */
        $auditContext = self::getContainer()->get(AuditContext::class);
        $auditContext->pushSuppressedEntityAudit([Hospital::class]);

        try {
            $hospital = HospitalFactory::createOne([
                'name' => $name,
                'isParticipating' => true,
            ]);
        } finally {
            $auditContext->popSuppressedEntityAudit();
        }

        $this->clearParticipatingSince($this->hospitalId($hospital));

        return $hospital;
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    private function insertHospitalAudit(
        int $hospitalId,
        string $action,
        array $changes,
        \DateTimeImmutable $occurredAt,
    ): void {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new AuditEntry(
            $occurredAt,
            'backfill-test-'.$hospitalId,
            null,
            'cli',
            $action,
            Hospital::class,
            (string) $hospitalId,
            $changes,
            null,
        ));
        $em->flush();
    }

    private function hospitalId(Hospital $hospital): int
    {
        $id = $hospital->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function clearParticipatingSince(int $hospitalId): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE hospital SET participating_since = NULL WHERE id = :id',
            ['id' => $hospitalId],
        );
    }

    private function fetchParticipatingSince(int $hospitalId): ?\DateTimeImmutable
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $value = $connection->fetchOne(
            'SELECT participating_since FROM hospital WHERE id = :id',
            ['id' => $hospitalId],
        );

        if (null === $value || false === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value);
    }

    private function hospitalBlock(string $display, string $hospitalName): string
    {
        if (1 !== preg_match('/Hospital #\d+ – '.preg_quote($hospitalName, '/').'\n.*?(?=Hospital #\d+ – |\nSummary\n|\n \[OK\])/s', $display, $matches)) {
            self::fail('Could not find report block for hospital '.$hospitalName);
        }

        return $matches[0];
    }

    private function createCommandTester(): CommandTester
    {
        /** @var BackfillHospitalParticipatingSinceCommand $command */
        $command = self::getContainer()->get(BackfillHospitalParticipatingSinceCommand::class);

        return new CommandTester($command);
    }
}
