<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
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
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OwnHospitalAllocationsExporterTest extends KernelTestCase
{
    use Factories;

    public function testWriteCsvIncludesHeaderAndScopedRows(): void
    {
        self::bootKernel();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $ownedHospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'CSV Owned Hospital']);
        $foreignHospital = HospitalFactory::createOne(['owner' => $other, 'name' => 'CSV Foreign Hospital']);
        $this->seedAllocationDependencies($ownedHospital);

        AllocationFactory::createOne([
            'hospital' => $ownedHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 10:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $foreignHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 11:00:00'),
        ]);

        $exporter = self::getContainer()->get(OwnHospitalAllocationsExporter::class);
        self::assertInstanceOf(OwnHospitalAllocationsExporter::class, $exporter);

        $filter = new OwnHospitalAllocationsExportFilter(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
        );

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        $written = $exporter->writeCsv($owner, $filter, $stream);
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        self::assertSame(1, $written);
        self::assertStringContainsString('arrivalAt', $csv);
        self::assertStringContainsString('dispatchArea', $csv);
        self::assertStringContainsString('CSV Owned Hospital', $csv);
        self::assertStringNotContainsString('CSV Foreign Hospital', $csv);
    }

    public function testWriteCsvRespectsSelectedHospitalIds(): void
    {
        self::bootKernel();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        $hospitalA = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Hospital Alpha']);
        $hospitalB = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Hospital Beta']);
        $this->seedAllocationDependencies($hospitalA);
        $this->seedAllocationDependencies($hospitalB);

        AllocationFactory::createOne([
            'hospital' => $hospitalA,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 10:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $hospitalB,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 11:00:00'),
        ]);

        $exporter = self::getContainer()->get(OwnHospitalAllocationsExporter::class);
        self::assertInstanceOf(OwnHospitalAllocationsExporter::class, $exporter);

        $filter = new OwnHospitalAllocationsExportFilter(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
            hospitalIds: [(int) $hospitalA->getId()],
        );

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        $written = $exporter->writeCsv($owner, $filter, $stream);
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        self::assertSame(1, $written);
        self::assertStringContainsString('Hospital Alpha', $csv);
        self::assertStringNotContainsString('Hospital Beta', $csv);
    }

    private function seedAllocationDependencies(object $hospital): void
    {
        ImportFactory::createOne(['name' => 'CSV Export Import', 'hospital' => $hospital]);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication Raw']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }
}
