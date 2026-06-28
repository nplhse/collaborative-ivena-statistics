<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Projection;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\Projection\ProjectionOverviewChangeDetector;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ProjectionOverviewChangeDetectorTest extends KernelTestCase
{
    use Factories;

    private ProjectionOverviewChangeDetector $detector;

    private AllocationStatsProjectionRebuildInterface $rebuilder;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->detector = $container->get(ProjectionOverviewChangeDetector::class);
        $this->rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);
    }

    public function testWillIntroduceNewHospitalsIsTrueForFirstImport(): void
    {
        $importId = $this->seedImportWithAllocation('DetectorFirstImport');

        self::assertTrue($this->detector->willIntroduceNewHospitals($importId));
    }

    public function testWillIntroduceNewHospitalsIsFalseAfterProjectionRebuild(): void
    {
        $importId = $this->seedImportWithAllocation('DetectorReimport');
        $this->rebuilder->rebuildForImport($importId);

        self::assertFalse($this->detector->willIntroduceNewHospitals($importId));
    }

    public function testWillIntroduceNewHospitalsIsFalseWhenHospitalExistsFromOtherImport(): void
    {
        $user = UserFactory::createOne(['username' => 'detector-other-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DetectorOtherState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DetectorOtherDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DetectorSharedHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
        ]);

        $firstImportId = $this->seedImportWithAllocation('DetectorOtherA', $hospital, $user, $state, $dispatchArea);
        $this->rebuilder->rebuildForImport($firstImportId);

        $secondImport = ImportFactory::createOne([
            'name' => 'DetectorOtherB',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);
        $secondImportId = (int) $secondImport->getId();
        $this->seedAllocation($secondImport, $hospital, $state, $dispatchArea);

        self::assertFalse($this->detector->willIntroduceNewHospitals($secondImportId));
    }

    public function testWillRemoveHospitalsFromProjectionIsTrueForOnlyImport(): void
    {
        $importId = $this->seedImportWithAllocation('DetectorDeleteOnly');
        $this->rebuilder->rebuildForImport($importId);

        self::assertTrue($this->detector->willRemoveHospitalsFromProjection($importId));
    }

    public function testWillRemoveHospitalsFromProjectionIsFalseWhenHospitalHasOtherImports(): void
    {
        $user = UserFactory::createOne(['username' => 'detector-delete-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DetectorDeleteState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DetectorDeleteDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DetectorDeleteHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
        ]);

        $firstImportId = $this->seedImportWithAllocation('DetectorDeleteA', $hospital, $user, $state, $dispatchArea);
        $secondImport = ImportFactory::createOne([
            'name' => 'DetectorDeleteB',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);
        $secondImportId = (int) $secondImport->getId();
        $this->seedAllocation($secondImport, $hospital, $state, $dispatchArea);

        $this->rebuilder->rebuildForImport($firstImportId);
        $this->rebuilder->rebuildForImport($secondImportId);

        self::assertFalse($this->detector->willRemoveHospitalsFromProjection($firstImportId));
    }

    private function seedImportWithAllocation(
        string $prefix,
        ?\App\Allocation\Domain\Entity\Hospital $hospital = null,
        ?\App\User\Domain\Entity\User $user = null,
        ?\App\Allocation\Domain\Entity\State $state = null,
        ?\App\Allocation\Domain\Entity\DispatchArea $dispatchArea = null,
    ): int {
        $user ??= UserFactory::createOne(['username' => strtolower($prefix).'-'.bin2hex(random_bytes(4))]);
        $state ??= StateFactory::createOne(['name' => $prefix.'State']);
        $dispatchArea ??= DispatchAreaFactory::createOne(['name' => $prefix.'Dispatch', 'state' => $state]);
        $hospital ??= HospitalFactory::createOne([
            'name' => $prefix.'Hospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
        ]);

        $import = ImportFactory::createOne([
            'name' => $prefix.'Import',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);
        $this->seedAllocation($import, $hospital, $state, $dispatchArea);

        return (int) $import->getId();
    }

    private function seedAllocation(
        \App\Import\Domain\Entity\Import $import,
        \App\Allocation\Domain\Entity\Hospital $hospital,
        \App\Allocation\Domain\Entity\State $state,
        \App\Allocation\Domain\Entity\DispatchArea $dispatchArea,
    ): void {
        SpecialityFactory::createOne(['name' => $import->getName().'Speciality']);
        DepartmentFactory::createOne(['name' => $import->getName().'Department']);
        AssignmentFactory::createOne(['name' => $import->getName().'Assignment']);
        IndicationRawFactory::createOne(['name' => $import->getName().'Indication', 'code' => random_int(900_000, 999_999)]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 10:20:00'),
        ]);
    }
}
