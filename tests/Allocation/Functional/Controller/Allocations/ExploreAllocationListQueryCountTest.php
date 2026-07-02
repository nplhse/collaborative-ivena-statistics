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
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Baseline without cache: each /explore/allocation request loads nine reference lists from the DB.
 * After cache warmup on the first request, the second request should skip those redundant queries.
 */
#[ResetDatabase]
final class ExploreAllocationListQueryCountTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    private const int MIN_SAVED_REFERENCE_QUERIES = 5;

    public function testSecondRequestUsesFewerReferenceQueries(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        AllocationFactory::createOne();

        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/explore/allocation?limit=10');
        self::assertResponseIsSuccessful();
        $firstCount = $this->doctrineQueryCount($client->getProfile());

        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/explore/allocation?limit=10');
        self::assertResponseIsSuccessful();
        $secondCount = $this->doctrineQueryCount($client->getProfile());

        self::assertGreaterThanOrEqual(
            self::MIN_SAVED_REFERENCE_QUERIES,
            $firstCount - $secondCount,
            sprintf(
                'Expected at least %d fewer DB queries on cached /explore/allocation request (first: %d, second: %d).',
                self::MIN_SAVED_REFERENCE_QUERIES,
                $firstCount,
                $secondCount,
            ),
        );
    }

    private function doctrineQueryCount(?\Symfony\Component\HttpKernel\Profiler\Profile $profile): int
    {
        self::assertNotNull($profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function seedDependencies(): void
    {
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
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }
}
