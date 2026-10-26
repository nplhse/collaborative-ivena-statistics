<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DeriveImportDataCoverageCommandTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $seed = $this->seed();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Would update 2 import data coverage summaries', $tester->getDisplay());
        self::assertStringContainsString('2026-03-01 → 2026-03-04, 2/4 days, 1 gap(s)', $tester->getDisplay());
        $this->em->clear();
        self::assertNull($this->import($seed['completedId'])->getDataCoverage());
    }

    public function testApplyStoresCoverageAndSkipsCalculatedImportsAfterwards(): void
    {
        $seed = $this->seed();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('Updated 2 import data coverage summaries', $tester->getDisplay());

        $this->em->clear();
        $coverage = $this->import($seed['completedId'])->getDataCoverage();
        self::assertNotNull($coverage);
        self::assertSame(['2026-03-01' => 1, '2026-03-04' => 1], $coverage->dailyCounts);
        self::assertTrue($this->import($seed['emptyId'])->getDataCoverage()?->isEmpty());
        self::assertNull($this->import($seed['failedId'])->getDataCoverage(), 'Failed imports are skipped.');

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('Updated 0 import data coverage summaries', $tester->getDisplay());
    }

    public function testAllRecalculatesStoredSummaries(): void
    {
        $seed = $this->seed();
        $import = $this->import($seed['completedId']);
        $import->applyDataCoverage(ImportDataCoverage::empty());
        $this->em->flush();

        $tester = $this->commandTester();
        $tester->execute(['--apply' => true, '--all' => true, '--hospital' => $seed['hospitalId']]);

        self::assertStringContainsString('Updated 2 import data coverage summaries', $tester->getDisplay());
        $this->em->clear();
        self::assertSame(2, $this->import($seed['completedId'])->getDataCoverage()?->records);
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:import:derive-data-coverage'));
    }

    private function import(int $id): Import
    {
        $import = $this->em->find(Import::class, $id);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    /**
     * @return array{hospitalId: int, completedId: int, emptyId: int, failedId: int}
     */
    private function seed(): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user, 'createdBy' => $user]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(900, 999)]);
        IndicationNormalizedFactory::createOne();
        $imports = [];
        foreach (['completed' => ImportStatus::COMPLETED, 'empty' => ImportStatus::PARTIAL, 'failed' => ImportStatus::FAILED] as $key => $status) {
            $imports[$key] = ImportFactory::createOne([
                'hospital' => $hospital,
                'createdBy' => $user,
                'type' => ImportType::ALLOCATION,
                'status' => $status,
            ]);
        }
        foreach (['2026-03-01 08:00:00', '2026-03-04 09:00:00'] as $createdAt) {
            AllocationFactory::createOne([
                'import' => $imports['completed'],
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'createdAt' => new \DateTimeImmutable($createdAt),
                'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
                'urgency' => AllocationUrgency::EMERGENCY,
            ]);
        }

        return [
            'hospitalId' => (int) $hospital->getId(),
            'completedId' => (int) $imports['completed']->getId(),
            'emptyId' => (int) $imports['empty']->getId(),
            'failedId' => (int) $imports['failed']->getId(),
        ];
    }
}
