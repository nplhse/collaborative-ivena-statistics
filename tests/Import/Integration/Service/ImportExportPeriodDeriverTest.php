<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

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
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureCoverageQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportExportPeriodDeriverTest extends KernelTestCase
{
    use Factories;

    #[Test]
    public function derivesAllocationAndClosurePeriodsAndExposesThemAsCoverage(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seed($connection);
        $deriver = self::getContainer()->get(ImportExportPeriodDeriver::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $coverage = self::getContainer()->get(ClosureCoverageQuery::class);

        $beforeDerivation = $coverage->allocations($seed['hospitalId']);
        self::assertNull($beforeDerivation, 'Not derived yet: legacy fallback.');

        $allocationImport = $entityManager->find(Import::class, $seed['allocationImportId']);
        self::assertInstanceOf(Import::class, $allocationImport);
        self::assertTrue($deriver->apply($allocationImport));
        $entityManager->flush();

        self::assertSame(1, $deriver->refreshClosureImports($seed['hospitalId']));
        $entityManager->clear();

        $allocationImport = $entityManager->find(Import::class, $seed['allocationImportId']);
        $closureImport = $entityManager->find(Import::class, $seed['closureImportId']);
        self::assertInstanceOf(Import::class, $allocationImport);
        self::assertInstanceOf(Import::class, $closureImport);
        self::assertSame('2026-03-01 00:00', $allocationImport->getExportStartsAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-04-01 00:00', $allocationImport->getExportEndsAt()?->format('Y-m-d H:i'));
        self::assertSame(ImportExportPeriodSource::DERIVED, $closureImport->getExportPeriodSource());
        self::assertSame('2026-03-01 00:00', $closureImport->getExportStartsAt()?->format('Y-m-d H:i'), 'Widened to the month covered by allocations.');
        self::assertSame('2026-04-01 00:00', $closureImport->getExportEndsAt()?->format('Y-m-d H:i'));

        $allocations = $coverage->allocations($seed['hospitalId']);
        self::assertNotNull($allocations);
        self::assertTrue($allocations->covers(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')));
        self::assertNotNull($coverage->closures($seed['hospitalId']));
        self::assertSame(
            1,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM closure_rebuild_request WHERE kind = 'analysis' AND hospital_id = :hospital", ['hospital' => $seed['hospitalId']]),
        );
    }

    /**
     * @return array{hospitalId: int, allocationImportId: int, closureImportId: int}
     */
    private function seed(Connection $connection): array
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
        $allocationImport = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
        ]);
        $closureImport = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::CLOSURE,
            'status' => ImportStatus::COMPLETED,
        ]);
        foreach (['2026-03-01 00:20:00', '2026-03-15 12:00:00', '2026-03-31 23:40:00'] as $createdAt) {
            AllocationFactory::createOne([
                'import' => $allocationImport,
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
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $closureImport->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-03-04 10:00:00',
            'ends_at' => '2026-03-29 11:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'period unit',
            'source_group_id' => 'period-group',
            'source_recorded_at' => '2026-03-04 09:00:00',
            'source_changed_at' => '2026-03-04 09:00:00',
        ]);

        return [
            'hospitalId' => (int) $hospital->getId(),
            'allocationImportId' => (int) $allocationImport->getId(),
            'closureImportId' => (int) $closureImport->getId(),
        ];
    }
}
