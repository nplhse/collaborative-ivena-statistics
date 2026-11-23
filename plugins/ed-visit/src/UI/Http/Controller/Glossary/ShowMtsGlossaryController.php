<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Http\Controller\Glossary;

use App\Allocation\Application\Explore\DataCatalog\DataCatalog;
use Cois\Plugin\EdVisit\UI\Glossary\MtsGlossaryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MTS glossary in Explore (#1056): categories, presentation diagrams and indicators. Definitions for
 * every participant (`^/explore` access rule); counts only for users with encounter access, for their
 * hospitals (ADR 028). Each section links to the catalog variables that store its codes, if the user may
 * see them (#1507).
 */
final class ShowMtsGlossaryController extends AbstractController
{
    public function __construct(
        private readonly MtsGlossaryFactory $glossaryFactory,
        private readonly DataCatalog $dataCatalog,
    ) {
    }

    #[Route('/help/glossary/mts', name: 'app_help_glossary_mts', methods: ['GET'], priority: 20)]
    public function __invoke(): Response
    {
        return $this->render('@EdVisit/glossary/mts.html.twig', [
            ...$this->glossaryFactory->overview(),
            'catalog_usages' => $this->dataCatalog->glossaryUsages('app_help_glossary_mts'),
        ]);
    }
}
