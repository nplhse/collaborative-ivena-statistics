<?php

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ListIndicationsControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        IndicationNormalizedFactory::createOne([
            'code' => '232',
            'name' => 'Test Indication',
        ]);
        IndicationNormalizedFactory::createMany(34, ['name' => 'Test Indication', 'code' => '232']);

        // Act
        $crawler = $client->request('GET', '/explore/indication');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Indications');
        self::assertSelectorTextContains('h2', 'Listing Indications');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'Code');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        $codeRowText = $rows->eq(0)->filter('td')->eq(0)->text();
        self::assertSame('232', trim($codeRowText));

        $nameRowText = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('Test Indication', trim($nameRowText));

        $userRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        IndicationNormalizedFactory::createOne(['name' => 'ABC']);
        IndicationNormalizedFactory::createOne(['name' => 'XYZ']);

        // Act
        $crawler = $client->request('GET', '/explore/indication?sortBy=name&orderBy=desc');

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
        IndicationNormalizedFactory::createMany(35);

        // Act
        $crawler = $client->request('GET', '/explore/indication?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
