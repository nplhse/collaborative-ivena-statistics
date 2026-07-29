<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SavedExplorerViewFavoriteControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use SeedsExplorerSystemViewsTrait;

    public function testToggleWithSafeRefererRedirectsBack(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();
        $viewId = $this->systemViewId();
        $token = $this->csrfTokenAfterVisit($client, 'explorer_favorite_'.$viewId);

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/views/'.$viewId.'/favorite/toggle',
            ['_token' => $token],
            server: ['HTTP_REFERER' => 'http://localhost/statistics/analysis/library'],
        );

        self::assertResponseRedirects('/statistics/analysis/library');
    }

    public function testToggleWithExternalRefererFallsBackToLibrary(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();
        $viewId = $this->systemViewId();
        $token = $this->csrfTokenAfterVisit($client, 'explorer_favorite_'.$viewId);

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/views/'.$viewId.'/favorite/toggle',
            ['_token' => $token],
            server: ['HTTP_REFERER' => 'https://evil.example/phish'],
        );

        self::assertResponseRedirects('/statistics/analysis/library');
    }

    public function testToggleWithoutRefererFallsBackToLibrary(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();
        $viewId = $this->systemViewId();
        $token = $this->csrfTokenAfterVisit($client, 'explorer_favorite_'.$viewId);

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/views/'.$viewId.'/favorite/toggle',
            ['_token' => $token],
        );

        self::assertResponseRedirects('/statistics/analysis/library');
    }

    private function systemViewId(): int
    {
        $view = self::getContainer()->get(SavedExplorerViewRepository::class)->findBySlug('allocations-over-time');
        $id = $view?->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function csrfTokenAfterVisit(KernelBrowser $client, string $tokenId): string
    {
        $client->request(Request::METHOD_GET, '/statistics/analysis/library');
        self::assertResponseIsSuccessful();

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
