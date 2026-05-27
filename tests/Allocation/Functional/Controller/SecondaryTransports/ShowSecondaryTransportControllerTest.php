<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\SecondaryTransports;

use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ShowSecondaryTransportControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;

    use ResetDatabase;
    use Factories;

    public function testDetailPageShowsSecondaryTransport(): void
    {
        $client = $this->createClientAsAreaUser();
        $st = SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        $id = $st->getId();
        self::assertNotNull($id);

        $client->request(Request::METHOD_GET, '/explore/secondary_transport/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#secondary-transport-name', 'Kapazitätsengpass');
        self::assertSelectorTextContains('a.btn-outline-secondary', 'Back to list');
    }
}
