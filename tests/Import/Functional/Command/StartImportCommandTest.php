<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

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
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class StartImportCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testStartCommandDispatchesAllocationAndClosureImports(): void
    {
        $seed = $this->seedReferenceGraph();
        $allocation = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'type' => ImportType::ALLOCATION,
        ]);
        $closure = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'type' => ImportType::CLOSURE,
        ]);

        $tester = $this->commandTester();
        $allocationId = $allocation->getId();
        $closureId = $closure->getId();
        self::assertNotNull($allocationId);
        self::assertNotNull($closureId);

        $allocationExit = $tester->execute(['importId' => (string) $allocationId]);
        self::assertSame(ImportDispatchExitCode::SUCCESS, $allocationExit);
        self::assertStringContainsString(
            sprintf('Dispatched Allocation import job for Import #%d', $allocationId),
            $tester->getDisplay(),
        );

        $closureExit = $tester->execute(['importId' => (string) $closureId]);
        self::assertSame(ImportDispatchExitCode::SUCCESS, $closureExit);
        self::assertStringContainsString(
            sprintf('Dispatched Closure import job for Import #%d', $closureId),
            $tester->getDisplay(),
        );
    }

    public function testStartCommandFailsForUnknownImport(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['importId' => '999999']);

        self::assertSame(ImportDispatchExitCode::FAILURE, $exitCode);
        self::assertStringContainsString('No Import found with ID 999999', $tester->getDisplay());
    }

    /**
     * @return array{user: object, hospital: object}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'start-import-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'StartImportState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'StartImportDispatchArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'StartImportHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'StartImportSpeciality']);
        DepartmentFactory::createOne(['name' => 'StartImportDepartment']);
        AssignmentFactory::createOne(['name' => 'StartImportAssignment']);
        OccasionFactory::createOne(['name' => 'StartImportOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'StartImportSecondaryTransport']);
        InfectionFactory::createOne(['name' => 'StartImportInfection']);
        IndicationRawFactory::createOne(['name' => 'StartImportRawIndication', 'code' => 800002]);
        IndicationNormalizedFactory::createOne(['name' => 'StartImportNormalizedIndication']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command = $application->find('app:import:start');

        return new CommandTester($command);
    }
}
