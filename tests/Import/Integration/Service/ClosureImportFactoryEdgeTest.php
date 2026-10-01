<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ClosureRowDTO;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Application\Mapping\ClosureHospitalProfile;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Mapping\ClosureImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ClosureImportFactoryEdgeTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        UserFactory::createOne();
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        self::getContainer()->get(ClosureImportFactory::class)->warm();
    }

    public function testUnsavedImportCannotBeReferenced(): void
    {
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Beispiel',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = new Import()->setHospital(
            $this->em->getReference(Hospital::class, $hospital->getId()),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import has no id assigned');

        self::getContainer()->get(ClosureImportFactory::class)->fromDto($this->validDto(), $import, $this->profileFor($hospital));
    }

    public function testHospitalWithoutIdCannotBeReferenced(): void
    {
        $hospital = new Hospital();
        $hospital->setName('Klinikum Beispiel');
        $import = new Import()->setHospital($hospital);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import has no hospital');

        self::getContainer()->get(ClosureImportFactory::class)->fromDto(
            $this->validDto(),
            $import,
            new ClosureHospitalGuard()->profile(1, [1 => 'Klinikum Beispiel'], ['Klinikum Beispiel']),
        );
    }

    private function validDto(): ClosureRowDTO
    {
        $dto = new ClosureRowDTO();
        $dto->hospitalShortName = 'Klinikum Beispiel';
        $dto->speciality = 'Innere Medizin';
        $dto->department = 'Kardiologie';
        $dto->careLevelLabel = 'Notfallversorgung';
        $dto->reasonLabel = 'k.A.';
        $dto->facilityKindLabel = 'Klinik';
        $dto->startsOn = '01.01.2026';
        $dto->startsAtTime = '00:10:00';
        $dto->endsOn = '01.01.2026';
        $dto->endsAtTime = '01:10:00';
        $dto->durationMinutes = 60;
        $dto->sourceRecordedAt = '01.01.2026 00:20:22';
        $dto->sourceChangedAt = '01.01.2026 00:20:22';

        return $dto;
    }

    private function profileFor(Hospital $hospital): ClosureHospitalProfile
    {
        $id = $hospital->getId();
        self::assertNotNull($id);

        return new ClosureHospitalGuard()->profile($id, [$id => 'Klinikum Beispiel'], ['Klinikum Beispiel']);
    }
}
