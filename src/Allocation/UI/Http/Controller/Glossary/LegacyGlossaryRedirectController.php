<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Glossary;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Keeps the glossary URLs released in v0.2 working after the move into the help (ADR 042). The data catalog
 * and the MTS glossary were never released under /explore and have no redirect.
 *
 * @todo Remove in v0.4-beta, see #1530.
 */
final class LegacyGlossaryRedirectController extends AbstractController
{
    #[Route('/explore/glossary', name: 'app_explore_glossary_index', methods: ['GET'])]
    public function index(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_help_glossary_index', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(
        '/explore/glossary/{slug}',
        name: 'app_explore_glossary_show',
        requirements: ['slug' => 'urgency|transport-type|hospital-classifications|clinical-indicators'],
        methods: ['GET'],
        priority: 10,
    )]
    public function show(string $slug, Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_help_glossary_show', [...$request->query->all(), 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
    }
}
