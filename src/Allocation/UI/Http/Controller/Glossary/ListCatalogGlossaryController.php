<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Glossary;

use App\Allocation\Application\Explore\Catalog\CatalogGlossaryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListCatalogGlossaryController extends AbstractController
{
    public function __construct(
        private readonly CatalogGlossaryFactory $glossaryFactory,
    ) {
    }

    #[Route('/explore/glossary', name: 'app_explore_glossary_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Allocation/glossary/index.html.twig', [
            'entries' => $this->glossaryFactory->indexEntries(),
        ]);
    }
}
