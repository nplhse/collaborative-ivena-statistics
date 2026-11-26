<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Contract\HelpSearchQueryInterface;
use App\Content\Application\Help\HelpNavigation;
use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageTranslationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Results of the help search (#596), with the help sidebar. Only articles the viewer may open are searched.
 *
 * @psalm-suppress UnusedClass
 */
final class HelpSearchController extends AbstractController
{
    public function __construct(
        private readonly HelpSearchQueryInterface $search,
        private readonly HelpNavigation $helpNavigation,
        private readonly PageTranslationResolver $translationResolver,
    ) {
    }

    #[Route('/help/search', name: 'app_help_search', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $root = $this->helpNavigation->root();
        if (!$root instanceof PageNavigationNode) {
            throw $this->createNotFoundException('The help section is not available.');
        }

        $query = trim(mb_substr($request->query->getString('q'), 0, HelpSearchQueryInterface::MAX_QUERY_LENGTH));
        $hits = '' === $query ? [] : $this->search->search(
            $query,
            array_map(static fn (PageNavigationNode $article): int => $article->pageId, $this->helpNavigation->articles()),
            $request->getLocale(),
            $this->translationResolver->getContentDefaultLocale(),
        );

        return $this->render('@Content/help/search.html.twig', [
            'help_root' => $root,
            'help' => true,
            'help_query' => $query,
            'hits' => $hits,
            'toc' => [],
        ]);
    }
}
