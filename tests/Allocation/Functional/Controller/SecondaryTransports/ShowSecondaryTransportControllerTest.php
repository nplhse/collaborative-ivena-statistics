<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\SecondaryTransports;

use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowSecondaryTransportControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsSecondaryTransport(): void
    {
        $client = $this->createClientAsAreaUser();
        $st = SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        $client->request(Request::METHOD_GET, '/explore/secondary_transport/'.$st->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#secondary-transport-name', 'Kapazitätsengpass');
        self::assertSelectorTextContains('a.btn-outline-secondary', 'Back to list');
    }
}
