<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Occasions;

use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowOccasionControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsOccasionCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $occasion = OccasionFactory::createOne(['name' => 'Primary emergency']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/occasion/'.$occasion->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#occasion-name', 'Primary emergency');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('occasion='.$occasion->getId(), $href);
    }
}
