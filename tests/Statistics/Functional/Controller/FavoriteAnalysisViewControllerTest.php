<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class FavoriteAnalysisViewControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testToggleFavoriteFromViewPageHeader(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analytics-view-favorite"].text-yellow');

        $this->submitViewFavoriteToggle($client, 'allocations_by_month');
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-favorite"].text-yellow');

        $this->submitViewFavoriteToggle($client, 'allocations_by_month');
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analytics-view-favorite"].text-yellow');
    }

    public function testToggleFavoriteRequiresValidCsrf(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_POST,
            '/statistics/analytics/favorites/allocations_by_month/toggle',
            ['_token' => 'invalid-token'],
        );

        $this->assertResponseStatusCodeSame(403);
    }

    private function submitViewFavoriteToggle(KernelBrowser $client, string $viewKey): void
    {
        $client->request(
            Request::METHOD_POST,
            '/statistics/analytics/favorites/'.$viewKey.'/toggle',
            ['_token' => $this->csrfToken($client, 'analytics_favorite_'.$viewKey)],
        );
        $this->assertResponseRedirects();
        $client->followRedirect();
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $request = $client->getRequest();
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId);
        } finally {
            $requestStack->pop();
        }

        return (string) $token->getValue();
    }
}
