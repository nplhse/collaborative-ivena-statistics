<?php

namespace App\Tests\Integration\Controller\Data;

use App\Factory\DispatchAreaFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

class AreaControllerTest extends WebTestCase
{
    use ResetDatabase;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createMany(50);

        // Act
        $client = static::createClient();
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
}
