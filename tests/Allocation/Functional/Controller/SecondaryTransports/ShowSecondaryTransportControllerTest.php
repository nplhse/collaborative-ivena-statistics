<?php

namespace App\Tests\Allocation\Functional\Controller\SecondaryTransports;

use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ShowSecondaryTransportControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testDetailPageShowsSecondaryTransport(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['username' => 'area-user']);
        $st = SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        $id = $st->getId();
        self::assertNotNull($id);

        $client->request('GET', '/explore/secondary_transport/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#secondary-transport-name', 'Kapazitätsengpass');
        self::assertSelectorTextContains('a.btn-outline-secondary', 'Back to list');
    }
}
