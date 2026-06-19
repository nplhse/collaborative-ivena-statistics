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
final class ListAllocationsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testFirstPageAndNextCursorWork(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        $base = new \DateTimeImmutable('2026-01-01 12:00:00');
        AllocationFactory::createMany(5, static fn (): array => [
            'createdAt' => $base->sub(new \DateInterval('PT5M')),
            'arrivalAt' => $base,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows);
        self::assertSelectorExists('ul.pagination a.page-link');

        $firstPageIds = $this->extractAllocationIds($crawler);
        self::assertCount(2, $firstPageIds);

        $nextHref = $this->findNextPageHref($crawler);
        self::assertNotNull($nextHref);
        $crawlerPage2 = $client->request(Request::METHOD_GET, $nextHref);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawlerPage2->filter('table.table tbody tr'));

        $secondPageIds = $this->extractAllocationIds($crawlerPage2);
        self::assertCount(0, array_intersect($firstPageIds, $secondPageIds));
    }

    public function testStableTieBreakerWithSameArrivalAtAvoidsDuplicates(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        $arrival = new \DateTimeImmutable('2026-02-01 10:00:00');

        AllocationFactory::createMany(3, static fn (): array => [
            'createdAt' => $arrival->sub(new \DateInterval('PT10M')),
            'arrivalAt' => $arrival,
            'age' => 40,
        ]);
        AllocationFactory::createOne([
            'createdAt' => $arrival->sub(new \DateInterval('PT20M')),
            'arrivalAt' => $arrival->sub(new \DateInterval('PT1H')),
            'age' => 41,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc');
        self::assertResponseIsSuccessful();
        $firstIds = $this->extractAllocationIds($crawler);
        self::assertCount(2, $firstIds);

        $nextHref = $this->findNextPageHref($crawler);
        self::assertNotNull($nextHref);
        $crawlerPage2 = $client->request(Request::METHOD_GET, $nextHref);
        self::assertResponseIsSuccessful();
        $secondIds = $this->extractAllocationIds($crawlerPage2);
        self::assertCount(2, $secondIds);

        $combined = array_merge($firstIds, $secondIds);
        self::assertCount(\count(array_unique($combined)), $combined);
    }

    public function testInvalidCursorFallsBackToFirstPage(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createMany(4);

        $firstPage = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc');
        $firstIds = $this->extractAllocationIds($firstPage);

        $invalidCursorPage = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc&cursor=not-a-valid-cursor');
        self::assertResponseIsSuccessful();
        $invalidIds = $this->extractAllocationIds($invalidCursorPage);

        self::assertSame($firstIds, $invalidIds);
    }

    public function testLimitPlusOneBehaviourAndFilterPersistence(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        $importOne = ImportFactory::createOne(['name' => 'Filtered Import']);
        $importTwo = ImportFactory::createOne(['name' => 'Other Import']);

        AllocationFactory::createMany(3, ['import' => $importOne]);
        AllocationFactory::createMany(2, ['import' => $importTwo]);

        $firstPage = $client->request(
            Request::METHOD_GET,
            sprintf('/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc&importId=%d', $importOne->getId())
        );
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ul.pagination a.page-link');

        $nextHref = $this->findNextPageHref($firstPage);
        self::assertNotNull($nextHref);
        self::assertStringContainsString(sprintf('importId=%d', $importOne->getId()), $nextHref);

        $secondPage = $client->request(Request::METHOD_GET, $nextHref);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $secondPage->filter('table.table tbody tr'));
        self::assertSelectorExists('ul.pagination li.page-item.disabled');
    }

    public function testIsInfectiousAndInfectionFiltersOnlyReturnMatchingAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $targetInfection = InfectionFactory::createOne(['name' => 'Influenza']);
        $otherInfection = InfectionFactory::createOne(['name' => 'Norovirus']);

        $matchingAllocation = AllocationFactory::createOne(['infection' => $targetInfection]);
        AllocationFactory::createOne(['infection' => $otherInfection]);
        AllocationFactory::createOne(['infection' => null]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf(
                '/explore/allocation?isInfectious=1&infection=%d&limit=50',
                $targetInfection->getId()
            )
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([(int) $matchingAllocation->getId()], $ids);
    }

    public function testIsVentilatedFilterOnlyReturnsVentilatedAllocations(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();

        $ventilatedAllocation = AllocationFactory::createOne(['isVentilated' => true]);
        AllocationFactory::createOne(['isVentilated' => false]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?isVentilated=1&limit=50'
        );

        self::assertResponseIsSuccessful();
        $ids = $this->extractAllocationIds($crawler);
        self::assertSame([(int) $ventilatedAllocation->getId()], $ids);
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
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }

    /**
     * @return list<int>
     */
    private function extractAllocationIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('td .btn-actions a') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (preg_match('/\/explore\/allocation\/(\d+)$/', $href, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }

    private function findNextPageHref(Crawler $crawler): ?string
    {
        $links = $crawler->filter('ul.pagination a.page-link');
        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $text = trim($link->textContent);
            if (str_contains($text, 'Next') || str_contains($text, 'Weiter')) {
                return $link->getAttribute('href');
            }
        }

        return null;
    }
}
