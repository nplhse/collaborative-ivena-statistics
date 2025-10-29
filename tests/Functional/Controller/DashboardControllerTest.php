<?php

namespace App\Tests\Functional\Controller;

use App\Factory\AllocationFactory;
use App\Factory\AssignmentFactory;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\IndicationNormalizedFactory;
use App\Factory\IndicationRawFactory;
use App\Factory\InfectionFactory;
use App\Factory\OccasionFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DashboardControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testHelloWorldIsDisplayed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hello, world!');
    }

    public function testStatsAreRendered(): void
    {
        $client = self::createClient();

        // Arrange
        UserFactory::createMany(5);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(2);
        ImportFactory::createMany(7);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();
        AllocationFactory::createMany(11);

        // Act
        $crawler = $client->request('GET', '/');

        // Assert
        self::assertResponseIsSuccessful();

        $cards = $crawler->filter('.card.card-sm .fs-2');

        self::assertCount(4, $cards);

        // Card 0: Allocations
        $allocText = trim($cards->eq(0)->text());
        self::assertStringContainsString('11', $allocText);

        // Card 1: Hospitals
        $hospitalText = trim($cards->eq(1)->text());
        self::assertStringContainsString('2', $hospitalText);

        // Card 2: Imports
        $importText = trim($cards->eq(2)->text());
        self::assertStringContainsString('7', $importText);

        // Card 3: Users
        $userText = trim($cards->eq(3)->text());
        self::assertStringContainsString('5', $userText);
    }
}
