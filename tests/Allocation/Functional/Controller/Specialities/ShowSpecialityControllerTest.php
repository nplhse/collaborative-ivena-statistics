<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Specialities;

use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowSpecialityControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsSpecialityCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $speciality = SpecialityFactory::createOne(['name' => 'Internal Medicine']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/speciality/'.$speciality->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#speciality-name', 'Internal Medicine');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('speciality='.$speciality->getId(), $href);
    }
}
