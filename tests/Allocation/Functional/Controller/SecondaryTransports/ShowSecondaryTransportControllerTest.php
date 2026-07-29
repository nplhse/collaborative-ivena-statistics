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
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-coverage"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');
        self::assertSelectorTextContains('[data-testid="catalog-actions"]', 'View allocations');
    }

    public function testAllocationsActionLinksToExploreListFilter(): void
    {
        $client = $this->createClientAsAreaUser();
        $st = SecondaryTransportFactory::createOne(['name' => 'Verlegung']);
        $crawler = $client->request(Request::METHOD_GET, '/explore/secondary_transport/'.$st->getPublicIdString());

        self::assertResponseIsSuccessful();
        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('/explore/allocation', $href);
        self::assertStringContainsString('secondaryTransport='.$st->getId(), $href);
    }
}
