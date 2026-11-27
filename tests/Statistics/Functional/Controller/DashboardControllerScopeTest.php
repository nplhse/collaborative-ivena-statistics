<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class DashboardControllerScopeTest extends DashboardControllerTestCase
{
    public function testStatisticsOverviewAcceptsScopeAndPeriodQueryParameters(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=last_12_months',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
        $this->assertSelectorExists('[data-testid="print-action"][aria-label="Print"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-scope-group"] option[value="public"][selected]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
    }

    /** #1609: the browser title follows the locale instead of a hard-coded "Overview". */
    public function testPageTitleIsTranslated(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=last_12_months');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Overview –');

        $client->followRedirects(true);
        $client->request(Request::METHOD_GET, '/locale/switch/de?_target_path=/statistics/?scope=public&period=last_12_months');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Übersicht –');
    }

    public function testHospitalCohortWithTooFewHospitalsRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=hospital_cohort&cohort=urban_basic',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('cohort=', $location);
    }

    public function testHospitalCohortWithEnoughHospitalsShowsTranslatedLabel(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedEligibleUrbanBasicCohort($client);

        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=hospital_cohort:urban_basic&period=last_12_months',
        );

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('stats.filter.cohort.', $content);
        self::assertStringContainsString('Urban · Basic', $content);
    }

    public function testStateScopeWithoutStateIdRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/?scope=state&period=last_12_months');

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
    }

    public function testUnknownStateIdRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/?scope=state&state=999999&period=last_12_months');

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
    }

    /**
     * The one HTTP check for hospital statistics access: a grant without STATISTICS must not open the hospital scope.
     * The permission cases themselves are covered in StatisticsFilterFactoryAccessTest.
     */
    public function testHospitalScopeWithoutStatisticsGrantRedirectsToPublic(): void
    {
        $client = self::createClient();
        $client->followRedirects(false);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
        ]);
        $client->loginUser($grantee);

        $client->request(Request::METHOD_GET, sprintf('/statistics/?scope=hospital:%d&period=last_12_months', $hospital->getId()));

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('hospital', $location);
    }

    public function testScopeSidebarShowsHospitalCohortGroup(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=last_12_months');

        $this->assertResponseIsSuccessful();
        $crawler->filter('#statistics-filters-drawer .offcanvas-body')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text('', true), 'Urban')
                || str_contains($node->text('', true), 'Rural')
        );

        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
    }
}
