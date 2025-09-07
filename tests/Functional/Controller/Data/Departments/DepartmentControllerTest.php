<?php

namespace App\Tests\Functional\Controller\Data\Specialities;

use App\Factory\DepartmentFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DepartmentControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        DepartmentFactory::createOne(['name' => 'Department']);
        DepartmentFactory::createMany(34, ['name' => 'Department']);

        // Act
        $crawler = $client->request('GET', '/data/department');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Departments');
        self::assertSelectorTextContains('h2', 'Listing Departments');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'ID');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('Department', trim($nameRowText));

        $userRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        DepartmentFactory::createOne(['name' => 'ABC']);
        DepartmentFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request('GET', '/data/department?sortBy=name&orderBy=desc');

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
        DepartmentFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/data/department?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
