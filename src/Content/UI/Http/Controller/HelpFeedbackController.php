<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Help\HelpFeedbackService;
use App\Content\Application\Help\HelpNavigation;
use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Domain\Entity\Page;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Was this article helpful?" below help articles (#596), for signed-in users. The article loads the widget into a
 * Turbo Frame; answers replace the frame. Without JavaScript the form posts normally and returns to the article.
 *
 * @psalm-suppress UnusedClass
 */
#[IsGranted('ROLE_USER')]
final class HelpFeedbackController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'help_feedback';

    public function __construct(
        private readonly HelpFeedbackService $feedback,
        private readonly HelpNavigation $helpNavigation,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/help/feedback/{pageId}', name: 'app_help_feedback', requirements: ['pageId' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function show(int $pageId): Response
    {
        [$page] = $this->article($pageId);

        return $this->renderWidget($page, $this->feedback->answerOf($this->user(), $page), 'question');
    }

    #[Route('/help/feedback/{pageId}', name: 'app_help_feedback_answer', requirements: ['pageId' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function answer(int $pageId, Request $request): Response
    {
        [$page, $article] = $this->article($pageId);
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->user();
        if ($request->request->has('comment')) {
            $this->feedback->comment($user, $page, $article->title, $request->getSchemeAndHttpHost().$article->url, $request->getLocale(), $request->request->getString('comment'));
            $state = 'commented';
        } else {
            $helpful = '1' === $request->request->getString('helpful');
            $this->feedback->answer($user, $page, $helpful, $request->getLocale());
            $state = $helpful ? 'thanks' : 'ask_comment';
        }

        if (!$request->headers->has('Turbo-Frame')) {
            return $this->redirect($article->url, Response::HTTP_SEE_OTHER);
        }

        return $this->renderWidget($page, $this->feedback->answerOf($user, $page), $state);
    }

    private function renderWidget(Page $page, ?bool $answer, string $state): Response
    {
        $response = $this->render('@Content/help/_feedback.html.twig', [
            'page_id' => $page->getId(),
            'answer' => $answer,
            'state' => $state,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The help article and how the viewer sees it; 404 for other pages and articles the viewer cannot open.
     *
     * @return array{Page, PageNavigationNode}
     */
    private function article(int $pageId): array
    {
        $page = $this->entityManager->find(Page::class, $pageId);
        if ($page instanceof Page && $page->isHelpArticle()) {
            foreach ($this->helpNavigation->articles() as $article) {
                if ($article->pageId === $pageId) {
                    return [$page, $article];
                }
            }
        }

        throw $this->createNotFoundException('The help article is not available.');
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        return $user;
    }
}
