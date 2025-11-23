<?php

namespace App\Tests\Functional\Controller\Data\Specialities;

use App\Factory\SpecialityFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SpecialityControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        SpecialityFactory::createMany(34, ['name' => 'Innere Medizin']);

        // Act
        $crawler = $client->request('GET', '/data/speciality');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Specialities');
        self::assertSelectorTextContains('h2', 'Listing Specialities');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Last changed at');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertSame('Innere Medizin', trim($nameRowText));

        $userRow = $rows->eq(0)->filter('td')->eq(2)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        SpecialityFactory::createOne(['name' => 'ABC']);
        SpecialityFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request('GET', '/data/speciality?sortBy=name&orderBy=desc');

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
        UserFactory::createOne(['username' => 'area-user']);
        SpecialityFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/data/speciality?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
