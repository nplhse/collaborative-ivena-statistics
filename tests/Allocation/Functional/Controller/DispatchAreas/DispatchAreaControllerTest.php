<?php

namespace App\Tests\Allocation\Functional\Controller\DispatchAreas;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DispatchAreaControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createMany(50);

        // Act
        $crawler = $client->request('GET', '/explore/dispatch_area');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Dispatch Areas');
        self::assertSelectorTextContains('h2', 'Listing Dispatch Areas');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'State');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 50 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertNotEmpty($nameRowText);

        $stateRow = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('Hessen', trim($stateRow));

        $userRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne(['name' => 'ABC']);
        DispatchAreaFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request('GET', '/explore/dispatch_area?sortBy=name&orderBy=desc');

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
        $client = static::createClient();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/explore/dispatch_area?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
