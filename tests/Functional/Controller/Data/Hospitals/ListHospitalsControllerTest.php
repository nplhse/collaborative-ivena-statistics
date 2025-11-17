<?php

namespace App\Tests\Functional\Controller\Data\Hospitals;

use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ListHospitalsControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createMany(5);
        HospitalFactory::createMany(10);

        // Act
        $crawler = $client->request('GET', '/data/hospital');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Hospitals');
        self::assertSelectorTextContains('h2', 'Listing Hospitals');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(3)', 'Dispatch Area');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-10 of 10 results.');

        $nameRowText = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertNotEmpty($nameRowText);

        $stateRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('Hessen', trim($stateRow));

        $userRow = $rows->eq(0)->filter('td')->eq(7)->text();
        self::assertStringContainsString('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'ACME Hospital']);
        HospitalFactory::createOne(['name' => 'XYZ Hospital']);

        // Act
        $crawler = $client->request('GET', '/data/hospital?sortBy=name&orderBy=desc');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows, 'We should see 2 rows of results.');
        $nameRow = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('XYZ Hospital', trim($nameRow));
    }

    public function testTableCanBePaginated(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/data/hospital?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
