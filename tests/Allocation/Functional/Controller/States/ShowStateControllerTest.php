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
        self::assertSelectorTextContains('.list-inline-item', 'State');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorNotExists('[data-testid="catalog-description"]');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorNotExists('[data-testid="catalog-basic-info"] .list-unstyled');
        self::assertSelectorTextContains('[data-testid="catalog-related-entities"]', 'Frankfurt');

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): ?string => $node->attr('href'),
        );
        self::assertContains('/explore/allocation?state='.$state->getId(), $actionHrefs);
        self::assertContains('/explore/hospital?state='.$state->getId(), $actionHrefs);
        self::assertContains('/explore/dispatch_area?state='.$state->getId(), $actionHrefs);
    }
}
