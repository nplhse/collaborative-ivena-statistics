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

final class DefaultControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testHelloWorldIsDisplayed(): void
    {
        $client = static::createClient();

        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
        AllocationFactory::createMany(25);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hello, world!');
    }
}
