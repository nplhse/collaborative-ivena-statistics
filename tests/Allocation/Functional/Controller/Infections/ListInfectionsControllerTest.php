<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Infections;

use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListInfectionsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createOne(['name' => 'Test Infection']);
        InfectionFactory::createMany(34, ['name' => 'Test Infection']);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/infection');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Infections');
        self::assertSelectorTextContains('h2', 'Infections');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Last changed at');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertSame('Test Infection', trim($nameRowText));

        $userRow = $rows->eq(0)->filter('td')->eq(2)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createOne(['name' => 'ABC']);
        InfectionFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/infection?sortBy=name&orderBy=desc');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows, 'We should see 2 rows of results.');
        $nameRow = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertSame('XYZ', trim($nameRow));
    }

    public function testTableCanBePaginated(): void
    {
        // Arrange
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createMany(35);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/infection?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }

    public function testPaginationLinksKeepFiltersAndSortResetsThePage(): void
    {
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createMany(30, ['name' => 'Alpha Infection']);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/infection?search=Alpha&sortBy=name&orderBy=desc&limit=25&page=2',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result-count', 'Showing 26-30 of 30 results.');
        self::assertGreaterThan(0, $crawler->filter('.pagination a.page-link')->count());

        $crawler->filter('.pagination a.page-link')->each(function (Crawler $node): void {
            $href = $node->attr('href');
            self::assertIsString($href);
            self::assertStringContainsString('search=Alpha', $href);
            self::assertStringContainsString('sortBy=name', $href);
            self::assertStringContainsString('orderBy=desc', $href);
            self::assertStringContainsString('limit=25', $href);
        });

        $sortHref = $crawler->filter('table.table thead a')->first()->attr('href');
        self::assertIsString($sortHref);
        self::assertStringNotContainsString('page=', $sortHref);
        self::assertStringContainsString('search=Alpha', $sortHref);

        $pageSizeHref = $crawler->filter('a[href*="limit=50"]')->first()->attr('href');
        self::assertIsString($pageSizeHref);
        self::assertStringContainsString('page=1', $pageSizeHref);
        self::assertStringContainsString('search=Alpha', $pageSizeHref);
    }

    public function testEmptySearchAndOutOfRangePageStayOnTheList(): void
    {
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createMany(5, ['name' => 'Known Infection']);

        $client->request(Request::METHOD_GET, '/explore/infection?search=missing-infection');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result-count', 'Showing 0-0 of 0 results.');

        $client->request(Request::METHOD_GET, '/explore/infection?page=9');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result-count', 'Showing 0-0 of 5 results.');
    }

    public function testInvalidPageIsRejected(): void
    {
        $client = $this->createClientAsAreaUser();

        $client->request(Request::METHOD_GET, '/explore/infection?page=0');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNumberedListRunsOneCountAndOneDataQuery(): void
    {
        $client = $this->createClientAsAreaUser();
        InfectionFactory::createMany(30, ['name' => 'Counted Infection']);

        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/explore/infection?page=2&limit=25');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $infectionSql = [];
        foreach ($collector->getQueries() as $queries) {
            if (!\is_array($queries)) {
                continue;
            }
            foreach ($queries as $query) {
                if (!\is_array($query) || !isset($query['sql']) || !\is_string($query['sql'])) {
                    continue;
                }
                if (str_contains(strtolower($query['sql']), 'infection')) {
                    $infectionSql[] = $query['sql'];
                }
            }
        }

        $countQueries = array_values(array_filter(
            $infectionSql,
            static fn (string $sql): bool => str_contains(strtoupper($sql), 'COUNT('),
        ));
        $dataQueries = array_values(array_filter(
            $infectionSql,
            static fn (string $sql): bool => !str_contains(strtoupper($sql), 'COUNT(') && str_contains(strtoupper($sql), 'LIMIT'),
        ));

        self::assertCount(1, $countQueries);
        self::assertCount(1, $dataQueries);
    }
}
