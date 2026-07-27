<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

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
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListAllocationsAccessTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

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

    private function seedLookupDependencies(): void
    {
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }

    /**
     * @return list<string>
     */
    private function extractAllocationIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('td .btn-actions a') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (preg_match('/\/explore\/allocation\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $href, $matches)) {
                $ids[] = $matches[1];
            }
        }

        return $ids;
    }
}
