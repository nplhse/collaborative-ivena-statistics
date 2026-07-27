<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
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
final class ListAllocationsFilterEdgesTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testHospitalAttributeFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $matchingHospital = HospitalFactory::createOne([
            'name' => 'Matching Attributes Hospital',
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'size' => HospitalSize::LARGE,
        ]);
        $otherHospital = HospitalFactory::createOne([
            'name' => 'Other Attributes Hospital',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'size' => HospitalSize::SMALL,
        ]);
        $matchingImport = ImportFactory::createOne(['hospital' => $matchingHospital]);
        $otherImport = ImportFactory::createOne(['hospital' => $otherHospital]);

        $matchingAllocation = AllocationFactory::createOne([
            'hospital' => $matchingHospital,
            'import' => $matchingImport,
        ]);
        AllocationFactory::createOne([
            'hospital' => $otherHospital,
            'import' => $otherImport,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?tier=%s&location=%s&size=%s&limit=50',
                HospitalTier::FULL->value,
                HospitalLocation::URBAN->value,
                HospitalSize::LARGE->value,
            ),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$matchingAllocation->getPublicIdString()], $this->extractAllocationIds($crawler));
    }

    public function testUrgencyAndGeographyFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $targetState = StateFactory::createOne(['name' => 'Urgency State']);
        $otherState = StateFactory::createOne(['name' => 'Other Urgency State']);
        $targetArea = DispatchAreaFactory::createOne(['name' => 'Urgency Area', 'state' => $targetState]);
        $otherArea = DispatchAreaFactory::createOne(['name' => 'Other Urgency Area', 'state' => $otherState]);

        $matchingAllocation = AllocationFactory::createOne([
            'urgency' => AllocationUrgency::EMERGENCY,
            'state' => $targetState,
            'dispatchArea' => $targetArea,
        ]);
        AllocationFactory::createOne([
            'urgency' => AllocationUrgency::OUTPATIENT,
            'state' => $otherState,
            'dispatchArea' => $otherArea,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?urgency=%d&state=%d&dispatchArea=%d&limit=50',
                AllocationUrgency::EMERGENCY->value,
                $targetState->getId(),
                $targetArea->getId(),
            ),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$matchingAllocation->getPublicIdString()], $this->extractAllocationIds($crawler));
    }

    public function testClinicalFlagFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $matchingAllocation = AllocationFactory::createOne([
            'requiresResus' => true,
            'requiresCathlab' => true,
            'isShock' => true,
            'isCPR' => true,
            'isPregnant' => true,
            'isWorkAccident' => true,
        ]);
        AllocationFactory::createOne([
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isShock' => false,
            'isCPR' => false,
            'isPregnant' => false,
            'isWorkAccident' => false,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?requiresResus=1&requiresCathlab=1&isShock=1&isCPR=1&isPregnant=1&isWorkAccident=1&limit=50',
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$matchingAllocation->getPublicIdString()], $this->extractAllocationIds($crawler));
    }

    public function testIndicationAndSecondaryTransportFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $targetIndication = IndicationNormalizedFactory::createOne([
            'name' => 'Target Indication',
            'code' => 4242,
        ]);
        $otherIndication = IndicationNormalizedFactory::createOne([
            'name' => 'Other Indication',
            'code' => 1337,
        ]);
        $targetTransport = SecondaryTransportFactory::createOne(['name' => 'Target Secondary']);
        $otherTransport = SecondaryTransportFactory::createOne(['name' => 'Other Secondary']);

        $matchingAllocation = AllocationFactory::createOne([
            'indicationNormalized' => $targetIndication,
            'secondaryTransport' => $targetTransport,
        ]);
        AllocationFactory::createOne([
            'indicationNormalized' => $otherIndication,
            'secondaryTransport' => $otherTransport,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?indication=%d&secondaryTransport=%d&limit=50',
                $targetIndication->getCode(),
                $targetTransport->getId(),
            ),
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$matchingAllocation->getPublicIdString()], $this->extractAllocationIds($crawler));
    }

    public function testSortByAgeOrdersAllocationsAscending(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $older = AllocationFactory::createOne(['age' => 72]);
        $younger = AllocationFactory::createOne(['age' => 19]);
        AllocationFactory::createOne(['age' => 45]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?sortBy=age&orderBy=asc&limit=50',
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame($younger->getPublicIdString(), $ids[0] ?? null);
        self::assertSame($older->getPublicIdString(), $ids[\count($ids) - 1] ?? null);
    }

    private function seedDependencies(): void
    {
        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication', 'code' => 1001]);
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
