<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Glossary;

use App\Allocation\Application\Explore\Catalog\CatalogGlossaryFactory;
use App\Allocation\Application\Explore\Catalog\CatalogGlossarySlug;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ShowCatalogGlossaryController extends AbstractController
{
    public function __construct(
        private readonly CatalogGlossaryFactory $glossaryFactory,
    ) {
    }

    #[Route(
        '/explore/glossary/{slug}',
        name: 'app_explore_glossary_show',
        requirements: ['slug' => 'urgency|transport-type|hospital-classifications|clinical-indicators'],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(string $slug): Response
    {
        $glossarySlug = CatalogGlossarySlug::tryFrom($slug);
        if (null === $glossarySlug) {
            throw new NotFoundHttpException();
        }

        $page = $this->glossaryFactory->create($glossarySlug);

        return $this->render('@Allocation/glossary/show.html.twig', [
            'page' => $page,
        ]);
    }
}
