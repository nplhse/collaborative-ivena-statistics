<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\DispatchAreas;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
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
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinik am Main',
            'owner' => UserFactory::createOne(['username' => 'hospital-owner']),
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/dispatch_area/'.$area->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dispatch-area-name', 'Frankfurt');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorTextContains('[data-testid="catalog-related-entities"]', 'Hessen');
        self::assertSelectorTextContains('[data-testid="catalog-related-entities"]', 'Klinik am Main');
        self::assertSelectorExists(
            '[data-testid="catalog-related-entities"] a[href="/explore/state/'.$state->getPublicIdString().'"]',
        );
        self::assertSelectorExists(
            '[data-testid="catalog-related-entities"] a[href="/explore/hospital/'.$hospital->getPublicIdString().'"]',
        );
        self::assertSelectorExists('[data-testid="catalog-basic-info"] a[href^="/explore/user/"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('dispatchArea='.$area->getId(), $href);
        self::assertSelectorNotExists('[data-testid="catalog-hospital-imports-link"]');
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

    public function testOwnerSeesImportLinkForHospitalInDispatchArea(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'da-owner']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Owned Klinik',
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/explore/dispatch_area/'.$area->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(
            '[data-testid="catalog-hospital-imports-link"][href="/import?hospitalId='.$hospital->getId().'"]',
        );

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertContains('/import?hospitalId='.$hospital->getId(), $actionHrefs);
    }

    public function testMultipleOwnedHospitalsDoNotAddSingleImportAction(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'da-multi-owner']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $first = HospitalFactory::createOne([
            'name' => 'Klinik A',
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $area,
        ]);
        $second = HospitalFactory::createOne([
            'name' => 'Klinik B',
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $area,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/explore/dispatch_area/'.$area->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(
            '[data-testid="catalog-hospital-imports-link"][href="/import?hospitalId='.$first->getId().'"]',
        );
        self::assertSelectorExists(
            '[data-testid="catalog-hospital-imports-link"][href="/import?hospitalId='.$second->getId().'"]',
        );

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertNotContains('/import?hospitalId='.$first->getId(), $actionHrefs);
        self::assertNotContains('/import?hospitalId='.$second->getId(), $actionHrefs);
    }
}
