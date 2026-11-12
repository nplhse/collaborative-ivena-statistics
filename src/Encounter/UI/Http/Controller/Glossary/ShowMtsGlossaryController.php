<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Glossary;

use App\Encounter\UI\Glossary\MtsGlossaryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MTS glossary in Explore (#1056): categories, presentation diagrams and indicators. Definitions for
 * every participant (`^/explore` access rule); counts only for users with encounter access, for their
 * hospitals (ADR 027).
 */
final class ShowMtsGlossaryController extends AbstractController
{
    public function __construct(
        private readonly MtsGlossaryFactory $glossaryFactory,
    ) {
    }

    #[Route('/explore/glossary/mts', name: 'app_explore_glossary_mts', methods: ['GET'], priority: 20)]
    public function __invoke(): Response
    {
        return $this->render('@Encounter/glossary/mts.html.twig', $this->glossaryFactory->overview());
    }
}
