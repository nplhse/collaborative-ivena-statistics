<?php

namespace App\Tests\Allocation\Functional\Controller\SecondaryTransports;

use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ListSecondaryTransportsControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        SecondaryTransportFactory::createMany(34, ['name' => 'Test Secondary Transport']);

        $crawler = $client->request('GET', '/explore/secondary_transport');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Secondary Transports');
        self::assertSelectorTextContains('h2', 'Secondary Transports');
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(1)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Last changed at');

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(25, $rows, 'We should see 25 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-25 of 35 results.');

        self::assertSelectorExists('a[href*="/explore/secondary_transport/"]');
    }

    public function testTableCanBeSorted(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        SecondaryTransportFactory::createOne(['name' => 'ABC']);
        SecondaryTransportFactory::createOne(['name' => 'XYZ']);

        $crawler = $client->request('GET', '/explore/secondary_transport?sortBy=name&orderBy=desc');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows);
        $nameCell = $rows->eq(0)->filter('td')->eq(0);
        self::assertStringContainsString('XYZ', $nameCell->text());
    }

    public function testTableCanBePaginated(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        SecondaryTransportFactory::createMany(35);

        $crawler = $client->request('GET', '/explore/secondary_transport?page=2');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows);
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
