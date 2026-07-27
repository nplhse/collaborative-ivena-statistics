<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Request;

final class ListAllocationsHospitalScopeTest extends ListAllocationsControllerTestCase
{
    public function testMyHospitalsScopeFiltersToAccessibleHospitals(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'allocation-scope-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['createdBy' => $owner, 'name' => 'Scope State']);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state, 'name' => 'Scope Area']);
        $ownHospital = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Own Hospital',
        ]);
        $otherOwner = UserFactory::createOne([
            'username' => 'allocation-scope-other-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $otherHospital = HospitalFactory::createOne([
            'createdBy' => $otherOwner,
            'owner' => $otherOwner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Other Hospital',
        ]);
        $this->seedLookupDependencies();

        $ownImport = ImportFactory::createOne(['name' => 'Own Import', 'hospital' => $ownHospital]);
        $otherImport = ImportFactory::createOne(['name' => 'Other Import', 'hospital' => $otherHospital]);

        $ownAllocation = AllocationFactory::createOne(['hospital' => $ownHospital, 'import' => $ownImport]);
        AllocationFactory::createOne(['hospital' => $otherHospital, 'import' => $otherImport]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?hospitalFilter=my_hospitals&limit=50',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="allocation-filter-hospital"]');
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$ownAllocation->getPublicIdString()], $ids);
    }

    public function testSingleHospitalFilterSelectsAccessibleHospital(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'allocation-filter-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Hospital A',
        ]);
        $hospitalB = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Hospital B',
        ]);
        $this->seedLookupDependencies();

        $importA = ImportFactory::createOne(['hospital' => $hospitalA]);
        $importB = ImportFactory::createOne(['hospital' => $hospitalB]);
        $allocationA = AllocationFactory::createOne(['hospital' => $hospitalA, 'import' => $importA]);
        AllocationFactory::createOne(['hospital' => $hospitalB, 'import' => $importB]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/explore/allocation?hospitalFilter=%d&limit=50', $hospitalA->getId()),
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$allocationA->getPublicIdString()], $ids);
    }

    public function testLegacyHospitalScopeQueryStillWorks(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'allocation-legacy-scope-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);
        $this->seedLookupDependencies();

        $import = ImportFactory::createOne(['hospital' => $hospital]);
        $allocation = AllocationFactory::createOne(['hospital' => $hospital, 'import' => $import]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?hospitalScope=my_hospitals&limit=50',
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$allocation->getPublicIdString()], $this->extractAllocationIds($crawler));
    }

    public function testMyHospitalsScopeWithHospitalDetailFiltersToSingleHospital(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'allocation-scope-detail-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Hospital A',
        ]);
        $hospitalB = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Hospital B',
        ]);
        $this->seedLookupDependencies();

        $importA = ImportFactory::createOne(['hospital' => $hospitalA]);
        $importB = ImportFactory::createOne(['hospital' => $hospitalB]);
        $allocationA = AllocationFactory::createOne(['hospital' => $hospitalA, 'import' => $importA]);
        AllocationFactory::createOne(['hospital' => $hospitalB, 'import' => $importB]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?hospitalScope=my_hospitals&hospital=%d&limit=50',
                $hospitalA->getId(),
            ),
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([$allocationA->getPublicIdString()], $ids);
    }
}
