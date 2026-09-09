<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Path;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RepairIndicationCorruptionCommandTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testDryRunListsQuoteBrokenImportAndSkipsMissingSource(): void
    {
        $seed = $this->seedReferenceGraph();
        $readyPath = $this->writeImportCsv('ready.csv');
        $readyImport = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Ready Quote Import',
            'filePath' => $readyPath['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-02 10:00:00'),
        ]);
        $missingImport = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Missing Quote Import',
            'filePath' => 'var/imports/missing-quote-repair.csv',
            'createdAt' => new \DateTimeImmutable('2025-06-03 10:00:00'),
        ]);

        $this->persistQuoteReject($readyImport);
        $this->persistQuoteReject($missingImport);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('requeue-ready', $display);
        self::assertStringContainsString('Ready Quote Import', $display);
        self::assertStringContainsString('skipped (missing source)', $display);
        self::assertStringContainsString('Missing Quote Import', $display);
        self::assertStringContainsString('not_found', $display);
        self::assertStringContainsString('Would dispatch: 1', $display);
        self::assertStringContainsString('were not requeued because the source CSV is missing', $display);
    }

    public function testDryRunDoesNotMergeStubRaws(): void
    {
        IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'Gefäßchirurgischer Notfall, sonstiger',
            'hash' => 'cmd-intact',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);
        IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'ßchirurgischer Notfall, sonstiger',
            'hash' => 'cmd-stub',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-requeue' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('stub', $tester->getDisplay());
        self::assertStringContainsString('ßchirurgischer Notfall, sonstiger', $tester->getDisplay());
        self::assertNotFalse(
            $this->em->getConnection()->fetchOne("SELECT id FROM indication_raw WHERE hash = 'cmd-stub'"),
        );
    }

    public function testDryRunWithoutQuoteRejectsDoesNotRequeueUnrelatedImports(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Unrelated Import',
            'filePath' => $this->writeImportCsv('unrelated.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-02 10:00:00'),
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Would dispatch: 0', $tester->getDisplay());
        self::assertStringNotContainsString('Unrelated Import', $tester->getDisplay());
    }

    public function testOverflowSinceDateIsInvalid(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
            '--skip-requeue' => true,
            '--since' => '2025-02-30',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --since date', $tester->getDisplay());
    }

    public function testDryRunRestoresCatalogStubWithoutSurvivorId(): void
    {
        IndicationNormalizedFactory::createOne([
            'code' => 299,
            'name' => 'Gefäßchirurgischer Notfall, sonstiger',
        ]);
        IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'ßchirurgischer Notfall, sonstiger',
            'hash' => 'cmd-catalog-stub',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-requeue' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('stub_restore', $display);
        self::assertStringContainsString('ßchirurgischer Notfall, sonstiger', $display);
    }

    public function testAppliesMergeAndRebuildsProjectionWithoutDryRun(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Merge Persist Import',
            'filePath' => $this->writeImportCsv('merge-persist.csv')['stored'],
        ]);
        IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'Gefäßchirurgischer Notfall, sonstiger',
            'hash' => 'cmd-intact-persist',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);
        $stub = IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'ßchirurgischer Notfall, sonstiger',
            'hash' => 'cmd-stub-persist',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        $hospital = $seed['hospital'];
        $state = $hospital->getState();
        $dispatchArea = $hospital->getDispatchArea();
        self::assertInstanceOf(State::class, $state);
        self::assertInstanceOf(DispatchArea::class, $dispatchArea);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $stub,
            'indicationNormalized' => null,
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--skip-requeue' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Repair finished.', $display);
        self::assertStringContainsString('Projection rebuilt for', $display);
        self::assertFalse(
            $this->em->getConnection()->fetchOne("SELECT id FROM indication_raw WHERE hash = 'cmd-stub-persist'"),
        );
    }

    public function testOnlyImportIdFiltersQuoteBrokenDiscovery(): void
    {
        $seed = $this->seedReferenceGraph();
        $included = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Included Quote Import',
            'filePath' => $this->writeImportCsv('included.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-02 10:00:00'),
        ]);
        $excluded = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Excluded Quote Import',
            'filePath' => $this->writeImportCsv('excluded.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-03 10:00:00'),
        ]);
        $this->persistQuoteReject($included);
        $this->persistQuoteReject($excluded);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
            '--only-import-id' => (string) $included->getId(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Included Quote Import', $display);
        self::assertStringNotContainsString('Excluded Quote Import', $display);
    }

    public function testDiscoversPzc332RejectWithoutMciMessages(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'PZC Quote Import',
            'filePath' => $this->writeImportCsv('pzc.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-02 10:00:00'),
        ]);

        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setLineNumber(3);
        $reject->setMessages(['unrelated']);
        $reject->setRow([
            'pzc' => '332731',
            'pzc_und_text' => 'STEMI',
            'manv' => '',
        ]);
        $this->em->persist($reject);
        $this->em->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('PZC Quote Import', $tester->getDisplay());
    }

    public function testDispatchesReadyQuoteBrokenImportWithoutDryRun(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Dispatch Quote Import',
            'filePath' => $this->writeImportCsv('dispatch.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2025-06-02 10:00:00'),
        ]);
        $this->persistQuoteReject($import);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--skip-merge' => true,
            '--skip-projection' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dispatched: 1', $display);
        self::assertStringContainsString('Repair finished.', $display);
    }

    public function testInvalidSinceReturnsInvalid(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
            '--skip-requeue' => true,
            '--since' => 'not-a-date',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --since date', $tester->getDisplay());
    }

    public function testIgnoresQuoteRejectsBeforeSinceCutoff(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'name' => 'Old Quote Import',
            'filePath' => $this->writeImportCsv('old.csv')['stored'],
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);
        $this->persistQuoteReject($import);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--skip-merge' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Old Quote Import', $tester->getDisplay());
    }

    private function persistQuoteReject(Import $import): void
    {
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setLineNumber(2);
        $reject->setMessages([
            'createdAt: not a valid datetime',
            'mciId should not be blank',
        ]);
        $reject->setRow([
            'pzc' => '332731',
            'pzc_und_text' => '',
            'manv' => 'Leitstelle Kassel (Disponent5)',
        ]);
        $this->em->persist($reject);
        $this->em->flush();
    }

    /**
     * @return array{stored: string, absolute: string}
     */
    private function writeImportCsv(string $basename): array
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $absolute = Path::join($projectDir, 'var', 'imports', 'repair-test-'.$basename);
        @mkdir(\dirname($absolute), 0775, true);
        file_put_contents($absolute, "\"a\";\"b\"\n\"1\";\"2\"\n");
        $this->tempFiles[] = $absolute;

        return [
            'stored' => 'var/imports/repair-test-'.$basename,
            'absolute' => $absolute,
        ];
    }

    /**
     * @return array{user: object, hospital: Hospital}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'repair-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'RepairState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'RepairDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'RepairHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'RepairSpeciality']);
        DepartmentFactory::createOne(['name' => 'RepairDepartment']);
        AssignmentFactory::createOne(['name' => 'RepairAssignment']);
        OccasionFactory::createOne(['name' => 'RepairOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'RepairSecondary']);
        InfectionFactory::createOne(['name' => 'RepairInfection']);
        IndicationRawFactory::createOne(['name' => 'RepairRaw', 'code' => 800010]);
        IndicationNormalizedFactory::createOne(['name' => 'RepairNormalized']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:import:repair-indication-corruption');

        return new CommandTester($command);
    }
}
