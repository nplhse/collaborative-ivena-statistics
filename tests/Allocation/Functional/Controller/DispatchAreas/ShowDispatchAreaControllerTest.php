<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\DispatchAreas;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowDispatchAreaControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsCatalogModulesAndOrientationMap(): void
    {
        $client = $this->createClientAsAreaUser();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        HospitalFactory::createOne([
            'name' => 'Klinik am Main',
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/dispatch_area/'.$area->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dispatch-area-name', 'Frankfurt');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorTextContains('[data-testid="catalog-basic-info"]', 'Hessen');
        self::assertSelectorTextContains('[data-testid="catalog-basic-info"]', 'Klinik am Main');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('dispatchArea='.$area->getId(), $href);
    }

    public function testDetailPageOmitsMapForUnknownDispatchAreaName(): void
    {
        $client = $this->createClientAsAreaUser();
        $state = StateFactory::createOne(['name' => 'Bayern']);
        $area = DispatchAreaFactory::createOne(['name' => 'Unknown Area XYZ', 'state' => $state]);

        $client->request(Request::METHOD_GET, '/explore/dispatch_area/'.$area->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorNotExists('[data-testid="catalog-orientation-map"]');
    }
}
