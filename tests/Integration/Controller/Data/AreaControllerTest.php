<?php

namespace App\Tests\Integration\Controller\Data;

use App\Factory\DispatchAreaFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AreaControllerTest extends WebTestCase
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
        $crawler = $client->request('GET', '/data/area');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('List Areas');
        self::assertSelectorTextContains('h2', 'List Areas');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'ID');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 50 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertNotEmpty($nameRowText);

        $stateRow = $rows->eq(0)->filter('td')->eq(2)->text();
        self::assertSame('Hessen', trim($stateRow));

        $userRow = $rows->eq(0)->filter('td')->eq(4)->text();
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
        $crawler = $client->request('GET', '/data/area?sortBy=name&orderBy=desc');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows, 'We should see 2 rows of results.');
        $nameRow = $rows->eq(0)->filter('td')->eq(1)->text();
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
        $crawler = $client->request('GET', '/data/area?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
