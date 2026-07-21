<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssessmentFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\ImportAssessmentAuditPurgeQuery;
use App\Allocation\UI\Console\Command\PurgeImportAssessmentAuditCommand;
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
final class PurgeImportAssessmentAuditCommandTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunReportsCandidatesWithoutDeleting(): void
    {
        $fixture = $this->seedImportAssessmentAuditFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: no audit_log rows were deleted.', $display);
        self::assertStringContainsString('Matching audit entries: 1', $display);
        self::assertStringContainsString('Re-run with --execute', $display);

        self::assertSame(1, $this->countAssessmentCreateAuditEntries());
        self::assertSame($fixture['auditEntryId'], $this->findLatestAssessmentCreateAuditEntryId());
    }

    public function testExplicitDryRunFlagReportsCandidatesWithoutDeleting(): void
    {
        $this->seedImportAssessmentAuditFixture();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Dry run: no audit_log rows were deleted.', $tester->getDisplay());
        self::assertSame(1, $this->countAssessmentCreateAuditEntries());
    }

    public function testExecuteDeletesImportLinkedAssessmentCreateAuditEntries(): void
    {
        $this->seedImportAssessmentAuditFixture();
        $this->seedUnrelatedAssessmentUpdateAuditEntry();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--execute' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Deleted 1 import-generated Assessment create audit entr', $tester->getDisplay());
        self::assertSame(0, $this->countImportLinkedAssessmentCreateAuditEntries());
        self::assertSame(1, $this->countAssessmentUpdateAuditEntries());
    }

    public function testSucceedsWhenNoCandidatesExist(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No import-generated Assessment create audit entries found.', $tester->getDisplay());
    }

    public function testPurgeQueryCountsAndDeletesCandidates(): void
    {
        $this->seedImportAssessmentAuditFixture();

        /** @var ImportAssessmentAuditPurgeQuery $query */
        $query = self::getContainer()->get(ImportAssessmentAuditPurgeQuery::class);

        self::assertSame(1, $query->countCandidates());
        $range = $query->fetchOccurredAtRange();
        self::assertNotNull($range);

        self::assertSame(1, $query->deleteCandidates());
        self::assertSame(0, $query->countCandidates());
        self::assertNull($query->fetchOccurredAtRange());
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(PurgeImportAssessmentAuditCommand::class);

        return new CommandTester($command);
    }

    /**
     * @return array{auditEntryId: int, assessmentId: int}
     */
    private function seedImportAssessmentAuditFixture(): array
    {
        $user = UserFactory::createOne(['username' => 'purge-audit-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        $occasion = OccasionFactory::createOne();
        $assignment = AssignmentFactory::createOne();
        $indicationNormalized = IndicationNormalizedFactory::createOne();
        IndicationRawFactory::createOne(['target' => $indicationNormalized]);

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        /** @var AuditContext $auditContext */
        $auditContext = self::getContainer()->get(AuditContext::class);
        $auditContext->pushSuppressedEntityAudit([Assessment::class, Allocation::class]);
        try {
            $assessment = AssessmentFactory::createOne();
            $allocation = AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'occasion' => $occasion,
                'assignment' => $assignment,
                'indicationNormalized' => $indicationNormalized,
                'assessment' => $assessment,
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'transportType' => AllocationTransportType::GROUND,
                'createdAt' => new \DateTimeImmutable('2025-01-07 10:19:00'),
                'arrivalAt' => new \DateTimeImmutable('2025-01-07 13:14:00'),
            ]);
        } finally {
            $auditContext->popSuppressedEntityAudit();
        }

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $auditEntry = new AuditEntry(
            new \DateTimeImmutable('2025-06-01 12:00:00'),
            'purge-test-request-id',
            $user,
            'messenger',
            'create',
            Assessment::class,
            (string) $assessment->getId(),
            [
                'airway' => ['old' => null, 'new' => 'frei'],
                'breathing' => ['old' => null, 'new' => 'spontan'],
                'circulation' => ['old' => null, 'new' => 'stabil'],
                'disability' => ['old' => null, 'new' => 'wach'],
            ],
            null,
        );
        $em->persist($auditEntry);
        $em->flush();

        return [
            'auditEntryId' => (int) $auditEntry->getId(),
            'assessmentId' => (int) $assessment->getId(),
            'allocationId' => (int) $allocation->getId(),
        ];
    }

    private function seedUnrelatedAssessmentUpdateAuditEntry(): void
    {
        $user = UserFactory::createOne(['username' => 'purge-audit-update-'.bin2hex(random_bytes(4))]);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $auditEntry = new AuditEntry(
            new \DateTimeImmutable('2025-06-02 12:00:00'),
            'purge-test-update-request-id',
            $user,
            'http',
            'update',
            Assessment::class,
            '999999',
            [
                'airway' => ['old' => 'frei', 'new' => 'verlegt'],
            ],
            null,
        );
        $em->persist($auditEntry);
        $em->flush();
    }

    private function countAssessmentCreateAuditEntries(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class = :class AND action = :action',
            ['class' => Assessment::class, 'action' => 'create'],
        );
    }

    private function countImportLinkedAssessmentCreateAuditEntries(): int
    {
        /** @var ImportAssessmentAuditPurgeQuery $query */
        $query = self::getContainer()->get(ImportAssessmentAuditPurgeQuery::class);

        return $query->countCandidates();
    }

    private function countAssessmentUpdateAuditEntries(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class = :class AND action = :action',
            ['class' => Assessment::class, 'action' => 'update'],
        );
    }

    private function findLatestAssessmentCreateAuditEntryId(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT id FROM audit_log WHERE entity_class = :class AND action = :action ORDER BY id DESC LIMIT 1',
            ['class' => Assessment::class, 'action' => 'create'],
        );
    }
}
