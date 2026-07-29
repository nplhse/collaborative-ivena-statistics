<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Infections;

use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowInfectionControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsInfectionCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $infection = InfectionFactory::createOne(['name' => 'MRSA']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/infection/'.$infection->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#infection-name', 'MRSA');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('infection='.$infection->getId(), $href);
    }
}
