<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\States;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowStateControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testListAndDetailPagesAreReachable(): void
    {
        $client = $this->createClientAsAreaUser();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);

        $client->request(Request::METHOD_GET, '/explore/state');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hessen');

        $crawler = $client->request(Request::METHOD_GET, '/explore/state/'.$state->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#state-name', 'Hessen');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorTextContains('[data-testid="catalog-basic-info"]', 'Frankfurt');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('state='.$state->getId(), $href);
    }
}
