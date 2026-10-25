<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class ListAllocationsPaginationTest extends ListAllocationsControllerTestCase
{
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
        self::assertSelectorExists('#result-count');
        self::assertSelectorExists('#page-result-count');
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

    public function testPreviousLinkWalksBackPageByPageWithEqualTimestamps(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        $arrival = new \DateTimeImmutable('2026-03-01 08:00:00');
        // Equal sort values force every page boundary onto the id tie-breaker.
        AllocationFactory::createMany(7, static fn (): array => [
            'createdAt' => $arrival->sub(new \DateInterval('PT10M')),
            'arrivalAt' => $arrival,
        ]);

        $page1 = $client->request(Request::METHOD_GET, '/explore/allocation?limit=3&sortBy=arrivalAt&orderBy=desc');
        self::assertResponseIsSuccessful();
        self::assertNull($this->findPreviousPageHref($page1), 'The first page has no previous link.');
        $page1Ids = $this->extractAllocationIds($page1);

        $page2 = $client->request(Request::METHOD_GET, (string) $this->findNextPageHref($page1));
        $page2Ids = $this->extractAllocationIds($page2);
        $page3 = $client->request(Request::METHOD_GET, (string) $this->findNextPageHref($page2));
        $page3Ids = $this->extractAllocationIds($page3);
        self::assertCount(1, $page3Ids);
        self::assertNull($this->findNextPageHref($page3), 'The last page has no next link.');

        $previousHref = $this->findPreviousPageHref($page3);
        self::assertNotNull($previousHref);
        self::assertStringContainsString('limit=3', $previousHref);
        $backToPage2 = $client->request(Request::METHOD_GET, $previousHref);
        self::assertResponseIsSuccessful();
        self::assertSame($page2Ids, $this->extractAllocationIds($backToPage2));

        $backToPage1 = $client->request(Request::METHOD_GET, (string) $this->findPreviousPageHref($backToPage2));
        self::assertSame($page1Ids, $this->extractAllocationIds($backToPage1));
        self::assertNull($this->findPreviousPageHref($backToPage1), 'Paging back to the start ends the previous links.');
        self::assertNotNull($this->findNextPageHref($backToPage1));

        $all = [...$page1Ids, ...$page2Ids, ...$page3Ids];
        self::assertCount(7, array_unique($all));
    }

    public function testPreviousLinkWorksForAscendingAgeSort(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        foreach ([30, 30, 41, 52, 52] as $age) {
            AllocationFactory::createOne(['age' => $age]);
        }

        $page1 = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=age&orderBy=asc');
        $page1Ids = $this->extractAllocationIds($page1);
        $page2 = $client->request(Request::METHOD_GET, (string) $this->findNextPageHref($page1));
        $page2Ids = $this->extractAllocationIds($page2);
        $page3 = $client->request(Request::METHOD_GET, (string) $this->findNextPageHref($page2));
        self::assertCount(1, $this->extractAllocationIds($page3));

        $backToPage2 = $client->request(Request::METHOD_GET, (string) $this->findPreviousPageHref($page3));
        self::assertSame($page2Ids, $this->extractAllocationIds($backToPage2));
        $backToPage1 = $client->request(Request::METHOD_GET, (string) $this->findPreviousPageHref($backToPage2));
        self::assertSame($page1Ids, $this->extractAllocationIds($backToPage1));
    }

    public function testPagingBackAfterDeletionsShowsAFullFirstPage(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        $base = new \DateTimeImmutable('2026-04-01 12:00:00');
        $allocations = [];
        for ($i = 0; $i < 5; ++$i) {
            $allocations[] = AllocationFactory::createOne([
                'createdAt' => $base->sub(new \DateInterval('P1D')),
                'arrivalAt' => $base->sub(new \DateInterval('PT'.$i.'M')),
            ]);
        }

        $page1 = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc');
        $page2 = $client->request(Request::METHOD_GET, (string) $this->findNextPageHref($page1));
        $previousHref = (string) $this->findPreviousPageHref($page2);

        // The newest row disappears while the user is on page 2.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->delete('allocation', ['id' => $allocations[0]->getId()]);

        $back = $client->request(Request::METHOD_GET, $previousHref);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->extractAllocationIds($back), 'A short first page is refilled.');
        self::assertNull($this->findPreviousPageHref($back));
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

    public function testCursorFromAnotherSortStartsOnTheFirstPage(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        foreach ([20, 35, 50, 65] as $age) {
            AllocationFactory::createOne(['age' => $age]);
        }

        $byArrival = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=arrivalAt&orderBy=desc');
        $nextHref = $this->findNextPageHref($byArrival);
        self::assertNotNull($nextHref);
        parse_str((string) parse_url($nextHref, PHP_URL_QUERY), $query);
        self::assertIsString($query['cursor'] ?? null);

        $firstByAge = $client->request(Request::METHOD_GET, '/explore/allocation?limit=2&sortBy=age&orderBy=asc');
        $withForeignCursor = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?limit=2&sortBy=age&orderBy=asc&cursor='.urlencode($query['cursor']),
        );

        self::assertResponseIsSuccessful();
        self::assertSame($this->extractAllocationIds($firstByAge), $this->extractAllocationIds($withForeignCursor));
        self::assertNull($this->findPreviousPageHref($withForeignCursor));
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
}
