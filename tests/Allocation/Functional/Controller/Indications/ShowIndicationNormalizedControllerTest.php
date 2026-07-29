<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowIndicationNormalizedControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsIndicationCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 101,
            'note' => null,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#indication-name', 'STEMI');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-description"]');
        self::assertSelectorExists('[data-testid="catalog-coverage"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');
        self::assertSelectorTextContains('[data-testid="catalog-actions"]', 'View allocations');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('/explore/allocation', $href);
        self::assertStringContainsString('indication=101', $href);
    }

    public function testDetailPageUsesEditorialNoteWhenPresent(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'Stroke',
            'code' => 202,
            'note' => 'Editorial definition for stroke.',
        ]);

        $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="catalog-description"]', 'Editorial definition for stroke.');
    }
}
