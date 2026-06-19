<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListHospitalsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = $this->createClientAsAreaUser();
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createMany(5);
        HospitalFactory::createMany(10);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital');

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
        $client = $this->createClientAsParticipant();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'ACME Hospital']);
        HospitalFactory::createOne(['name' => 'XYZ Hospital']);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?sortBy=name&orderBy=desc');

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
        $client = $this->createClientAsParticipant();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(35);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
    }
}
