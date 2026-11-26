<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Export;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Keeps the pre-#1633 URLs of the allocation export working after its move into the Tools section (ADR 031).
 * Every old route name and path redirects permanently (308) to its tool route, with the query string, so a form
 * opened before the move keeps its method and body. Access is checked by the target.
 *
 * @todo Remove in v0.4-beta, see #1160.
 */
final class LegacyAllocationsExportRedirectController extends AbstractController
{
    #[Route('/hospitals/export/allocations', name: 'app_hospitals_export_allocations', methods: ['GET'])]
    public function show(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_allocation_export', $request);
    }

    #[Route('/hospitals/export/allocations/estimate', name: 'app_hospitals_export_allocations_estimate', methods: ['POST'])]
    public function estimate(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_allocation_export_estimate', $request);
    }

    #[Route('/hospitals/export/allocations/download', name: 'app_hospitals_export_allocations_download', methods: ['POST'])]
    public function download(Request $request): RedirectResponse
    {
        return $this->redirectPermanently('app_tools_allocation_export_download', $request);
    }

    private function redirectPermanently(string $route, Request $request): RedirectResponse
    {
        return $this->redirectToRoute($route, $request->query->all(), Response::HTTP_PERMANENTLY_REDIRECT);
    }
}
