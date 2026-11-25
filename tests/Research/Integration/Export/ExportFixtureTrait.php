<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Export;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;

/**
 * Four urban hospitals with one allocation each and one rural hospital with two, each with its own import: grouped by
 * location, the urban group has four hospitals and the rural group one, below the minimum of three.
 */
trait ExportFixtureTrait
{
    private function seedHospitals(): void
    {
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne(['name' => 'Testland']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Testleitstelle', 'state' => $state]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => 999001]);

        $hospital = static fn (HospitalLocation $location): Hospital => HospitalFactory::createOne([
            'state' => $state, 'dispatchArea' => $dispatchArea, 'tier' => HospitalTier::FULL, 'location' => $location, 'owner' => $owner,
        ]);
        foreach ([HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::URBAN, HospitalLocation::RURAL] as $location) {
            $current = $hospital($location);
            $import = ImportFactory::createOne(['hospital' => $current, 'createdBy' => $owner, 'status' => ImportStatus::COMPLETED]);
            foreach (range(1, HospitalLocation::RURAL === $location ? 2 : 1) as $ignored) {
                AllocationFactory::createOne(['hospital' => $current, 'import' => $import, 'state' => $state, 'dispatchArea' => $dispatchArea]);
            }
        }
    }
}
