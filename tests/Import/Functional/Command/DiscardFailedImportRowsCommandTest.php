<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DiscardFailedImportRowsCommandTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private Hospital $hospital;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $this->user = UserFactory::createOne(['username' => 'discard-failed-rows']);
        $this->hospital = HospitalFactory::createOne(['name' => 'Discard Hospital', 'state' => $state, 'dispatchArea' => $dispatchArea]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
    }

    public function testDryRunReportsAndChangesNothing(): void
    {
        $id = $this->failedImportWithRows(2, withSourceFile: true);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('discard', $tester->getDisplay());
        self::assertSame(2, $this->countAllocations($id));
    }

    public function testForceRemovesRowsKeepsRejectsAndASecondRunChangesNothing(): void
    {
        $id = $this->failedImportWithRows(2, withSourceFile: true);
        $keptId = $this->completedImportWithRows(1);
        $this->connection->insert('import_reject', ['import_id' => $id, 'line_number' => 3, 'messages' => '[]', 'row' => '{}', 'created_at' => '2026-01-01 00:00:00']);

        self::assertSame(0, $this->tester()->execute(['--force' => true]));

        self::assertSame(0, $this->countAllocations($id));
        self::assertSame(1, $this->countAllocations($keptId));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$id]));

        $second = $this->tester();
        self::assertSame(0, $second->execute(['--force' => true]));
        self::assertStringContainsString('No failed import holds rows', $second->getDisplay());
        self::assertSame(1, $this->countAllocations($keptId));
    }

    public function testRowsAreKeptWhenTheSourceFileIsMissing(): void
    {
        $id = $this->failedImportWithRows(1, withSourceFile: false);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('keep: source file missing', $tester->getDisplay());
        self::assertSame(1, $this->countAllocations($id));
    }

    public function testRowsOfTheLastSuccessfulRunAreKeptAfterAFailedPrecondition(): void
    {
        $id = $this->failedImportWithRows(1, withSourceFile: true);
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $run = $lifecycle->register($id, ImportRunTrigger::Console);
        $lifecycle->fail(new ImportRunContext($id, $run->getId(), false), ImportRunReasonCode::MissingImportPermission);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('keep: last successful run', $tester->getDisplay());
        self::assertSame(1, $this->countAllocations($id));
    }

    public function testListsOlderImportsToReprocessAfterRowsWereReplaced(): void
    {
        $olderId = $this->completedImportWithRows(1);
        $this->connection->update('import', ['created_at' => '2025-01-01 00:00:00'], ['id' => $olderId]);
        $id = $this->failedImportWithRows(1, withSourceFile: true);
        $this->connection->update('import', ['rows_deduplicated_replaced' => 1, 'created_at' => '2025-06-01 00:00:00'], ['id' => $id]);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('app:import:start '.$olderId, $tester->getDisplay());
    }

    private function failedImportWithRows(int $rows, bool $withSourceFile): int
    {
        $import = ImportFactory::createOne([
            'hospital' => $this->hospital,
            'createdBy' => $this->user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::FAILED,
            'filePath' => $withSourceFile ? $this->writeSourceFile() : 'var/imports/_tests/missing-'.bin2hex(random_bytes(6)).'.csv',
        ]);
        $this->createAllocations($import, $rows);

        return (int) $import->getId();
    }

    private function completedImportWithRows(int $rows): int
    {
        $import = ImportFactory::createOne([
            'hospital' => $this->hospital,
            'createdBy' => $this->user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
        ]);
        $this->createAllocations($import, $rows);

        return (int) $import->getId();
    }

    private function createAllocations(Import $import, int $rows): void
    {
        AllocationFactory::createMany($rows, [
            'import' => $import,
            'hospital' => $this->hospital,
            'assessment' => null,
            'infection' => null,
            'occasion' => null,
            'secondaryTransport' => null,
            'indicationNormalized' => null,
        ]);
    }

    private function writeSourceFile(): string
    {
        $base = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $dir = $base.'/_tests/discard-failed/'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0775, true));
        $path = $dir.'/source.csv';
        self::assertNotFalse(file_put_contents($path, "header\n"));

        return ltrim(substr($path, \strlen($projectDir)), '/');
    }

    private function countAllocations(int $importId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM allocation WHERE import_id = ?', [$importId]);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:import:discard-failed-rows'));
    }
}
