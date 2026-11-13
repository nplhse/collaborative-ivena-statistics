<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Keeps the pre-#1128 URLs of the indication matching review working after its move into the Tools
 * section (ADR 031). Every old route name and path redirects permanently to its canonical tool route,
 * with the query string. Access is checked by the target.
 *
 * @todo Remove in v0.4-beta, see #1160.
 */
final class LegacyIndicationReviewRedirectController extends AbstractController
{
    #[Route('/explore/indication/raw/review', name: 'app_explore_indication_raw_review_worklist', methods: ['GET'])]
    public function worklist(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_indication_review', [], $request);
    }

    #[Route('/explore/indication/raw/review/start/matching', name: 'app_explore_indication_raw_review_start_matching', methods: ['GET'])]
    public function startMatching(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_indication_review_start_matching', [], $request);
    }

    #[Route('/explore/indication/raw/review/start/reviewing', name: 'app_explore_indication_raw_review_start_reviewing', methods: ['GET'])]
    public function startReviewing(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_indication_review_start_reviewing', [], $request);
    }

    /**
     * The review form posts to this URL, so a form opened before the move keeps its method and body (308).
     */
    #[Route(
        '/explore/indication/raw/review/{publicId}',
        name: 'app_explore_indication_raw_review',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function item(string $publicId, Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_indication_review_item', ['publicId' => $publicId], $request);
    }

    #[Route(
        '/explore/indication/raw/review/{publicId}/skip',
        name: 'app_explore_indication_raw_review_skip',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function skip(string $publicId, Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_indication_review_skip', ['publicId' => $publicId], $request);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function redirectPermanently(string $route, array $parameters, Request $request): RedirectResponse
    {
        return $this->redirectToRoute(
            $route,
            [...$request->query->all(), ...$parameters],
            Response::HTTP_PERMANENTLY_REDIRECT,
        );
    }
}
