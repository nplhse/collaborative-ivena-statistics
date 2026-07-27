<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
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
}
