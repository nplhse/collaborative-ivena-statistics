<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class LegacyAnalyticsRedirectControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testAnalyticsLibraryRedirectsToExplorerLibrary(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        $this->assertResponseRedirects('/statistics/analysis/library?scope=public&period=all', 301);
    }

    public function testAnalyticsViewRedirectsToMappedExplorerView(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/gender_distribution?scope=public&period=all',
        );

        $this->assertResponseRedirects('/statistics/analysis/explorer/gender-distribution?scope=public&period=all', 301);
    }

    public function testUnknownAnalyticsViewRedirectsToExplorerLibrary(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/resus_by_hour?scope=public&period=all',
        );

        $this->assertResponseRedirects('/statistics/analysis/library?scope=public&period=all', 301);
    }
}
