<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DeleteSavedExplorerViewControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOwnerCanDeleteAPublicView(): void
    {
        $client = self::createClient();
        $owner = $this->loginAsParticipant($client);
        $viewId = $this->persistView($owner, 'Public copy', true);
        $token = $this->csrfTokenAfterVisit($client, 'explorer_view_delete_'.$viewId);

        $client->request(Request::METHOD_POST, '/statistics/analysis/explorer/views/'.$viewId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/statistics/analysis/library');
        self::assertNull(self::getContainer()->get(SavedExplorerViewRepository::class)->find($viewId));
    }

    public function testDeleteRejectsAnotherUsersPublicView(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $viewId = $this->persistView($owner, 'Someone else', true);
        $this->loginAsParticipant($client);
        $token = $this->csrfTokenAfterVisit($client, 'explorer_view_delete_'.$viewId);

        $client->request(Request::METHOD_POST, '/statistics/analysis/explorer/views/'.$viewId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNotNull(self::getContainer()->get(SavedExplorerViewRepository::class)->find($viewId));
    }

    public function testDeleteOfAnUnknownViewIsNotFound(): void
    {
        $client = self::createClient();
        $this->loginAsParticipant($client);
        $token = $this->csrfTokenAfterVisit($client, 'explorer_view_delete_999999');

        $client->request(Request::METHOD_POST, '/statistics/analysis/explorer/views/999999/delete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteRejectsAnInvalidCsrfToken(): void
    {
        $client = self::createClient();
        $owner = $this->loginAsParticipant($client);
        $viewId = $this->persistView($owner, 'Still here', false);

        $client->request(Request::METHOD_POST, '/statistics/analysis/explorer/views/'.$viewId.'/delete', [
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull(self::getContainer()->get(SavedExplorerViewRepository::class)->find($viewId));
    }

    private function persistView(\App\User\Domain\Entity\User $owner, string $title, bool $public): int
    {
        $view = new SavedExplorerView(
            slug: null,
            title: $title,
            category: 'My views',
            configJson: ['schemaVersion' => 4, 'title' => $title],
            isSystem: false,
            visibility: $public
                ? \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Public
                : \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility::Private,
        );
        $view->setCreatedBy($owner);
        $repository = self::getContainer()->get(SavedExplorerViewRepository::class);
        $repository->save($view);
        $id = $view->getId();
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
            if ($request->hasSession()) {
                $request->getSession()->save();
            }
        } finally {
            $requestStack->pop();
        }

        return (string) $token->getValue();
    }
}
