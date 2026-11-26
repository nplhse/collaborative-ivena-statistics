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

final class ListAllocationsAccessTest extends ListAllocationsControllerTestCase
{
    public function testListRedirectsWhenNotAuthenticated(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseRedirects('/login');
    }

    public function testListIsForbiddenWithoutParticipantRole(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignHospitalFilterDoesNotLeakAllocations(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'allocation-access-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $otherOwner = UserFactory::createOne([
            'username' => 'allocation-access-other-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['createdBy' => $owner, 'name' => 'Access State']);
        $dispatchArea = DispatchAreaFactory::createOne([
            'createdBy' => $owner,
            'state' => $state,
            'name' => 'Access Area',
        ]);
        $ownHospital = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Own Access Hospital',
        ]);
        $foreignHospital = HospitalFactory::createOne([
            'createdBy' => $otherOwner,
            'owner' => $otherOwner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'name' => 'Foreign Access Hospital',
        ]);
        $this->seedLookupDependencies();

        $ownImport = ImportFactory::createOne(['name' => 'Own Access Import', 'hospital' => $ownHospital]);
        $foreignImport = ImportFactory::createOne(['name' => 'Foreign Access Import', 'hospital' => $foreignHospital]);
        AllocationFactory::createOne(['hospital' => $ownHospital, 'import' => $ownImport]);
        $foreignAllocation = AllocationFactory::createOne(['hospital' => $foreignHospital, 'import' => $foreignImport]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/explore/allocation?hospitalFilter=%d&limit=50', $foreignHospital->getId()),
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([], $ids);
        self::assertNotContains($foreignAllocation->getPublicIdString(), $ids);
    }

    /**
     * Without a hospital filter a participant sees only their own hospital's allocations (#1566): one allocation in
     * the own hospital, one in a foreign one; the administrator sees both and may choose "All hospitals".
     */
    public function testWithoutFilterParticipantsSeeOnlyOwnHospitalsAndAdministratorsSeeAll(): void
    {
        $client = self::createClient();
        [$owner, $own, $foreign] = $this->ownAndForeignAllocation('default');
        $admin = UserFactory::createOne(['username' => 'allocation-access-admin', 'roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=50');

        self::assertResponseIsSuccessful();
        self::assertSame([$own], $this->extractAllocationIds($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="allocation-filter-hospital"] option[value=""]'));
        self::assertSame('my_hospitals', $crawler->filter('[data-testid="allocation-filter-hospital"] option[selected]')->attr('value'));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=50');

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        sort($ids);
        $expected = [$own, $foreign];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertCount(1, $crawler->filter('[data-testid="allocation-filter-hospital"] option[value=""]'));
    }

    public function testParticipantOpensOnlyTheCaseFilesOfOwnHospitals(): void
    {
        $client = self::createClient();
        [$owner, $own, $foreign] = $this->ownAndForeignAllocation('case-file');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/explore/allocation/'.$own);
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$foreign);
        self::assertResponseStatusCodeSame(403);
    }

    public function testParticipantWithoutHospitalSeesNoAllocationsAndTheAccessRequest(): void
    {
        $client = self::createClient();
        $this->ownAndForeignAllocation('none');
        $participant = UserFactory::createOne(['username' => 'allocation-access-none', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $client->loginUser($participant);
        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=50');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->extractAllocationIds($crawler));
        self::assertCount(1, $crawler->filter('a[href="/hospitals/requests/new"]'));
    }

    /**
     * @return array{0: \App\User\Domain\Entity\User, 1: string, 2: string} owner, own and foreign allocation public id
     */
    private function ownAndForeignAllocation(string $prefix): array
    {
        $owner = UserFactory::createOne(['username' => $prefix.'-owner', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $otherOwner = UserFactory::createOne(['username' => $prefix.'-other-owner', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $owner, 'name' => $prefix.' State']);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state, 'name' => $prefix.' Area']);
        $ownHospital = HospitalFactory::createOne(['createdBy' => $owner, 'owner' => $owner, 'dispatchArea' => $dispatchArea, 'state' => $state, 'name' => $prefix.' Own']);
        $foreignHospital = HospitalFactory::createOne(['createdBy' => $otherOwner, 'owner' => $otherOwner, 'dispatchArea' => $dispatchArea, 'state' => $state, 'name' => $prefix.' Foreign']);
        $this->seedLookupDependencies();

        $own = AllocationFactory::createOne(['hospital' => $ownHospital, 'import' => ImportFactory::createOne(['hospital' => $ownHospital])]);
        $foreign = AllocationFactory::createOne(['hospital' => $foreignHospital, 'import' => ImportFactory::createOne(['hospital' => $foreignHospital])]);

        return [$owner, $own->getPublicIdString(), $foreign->getPublicIdString()];
    }
}
