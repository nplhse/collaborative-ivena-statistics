<?php

namespace App\Tests\Functional\Controller\Data\Assignments;

use App\Factory\AssignmentFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ListAssignmentsControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        AssignmentFactory::createMany(34, ['name' => 'Test Assignment']);

        // Act
        $crawler = $client->request('GET', '/data/assignment');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Assignments');
        self::assertSelectorTextContains('h2', 'Listing Assignments');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'ID');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('Test Assignment', trim($nameRowText));

        $userRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        AssignmentFactory::createOne(['name' => 'ABC']);
        AssignmentFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request('GET', '/data/assignment?sortBy=name&orderBy=desc');

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
        UserFactory::createOne(['username' => 'area-user']);
        AssignmentFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/data/assignment?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
