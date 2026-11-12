<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Glossary;

use App\Encounter\UI\Glossary\MtsGlossaryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One MTS presentation diagram (#1056) with the indicators seen with it in the encounters the current
 * user may see, filterable by name or code.
 */
final class ShowMtsDiagramController extends AbstractController
{
    public function __construct(
        private readonly MtsGlossaryFactory $glossaryFactory,
    ) {
    }

    #[Route(
        '/explore/glossary/mts/diagram/{code}',
        name: 'app_explore_glossary_mts_diagram',
        requirements: ['code' => '[a-z0-9_]{1,64}'],
        methods: ['GET'],
        priority: 20,
    )]
    public function __invoke(Request $request, string $code): Response
    {
        $page = $this->glossaryFactory->diagram($code, mb_substr($request->query->getString('q'), 0, 100));
        if (null === $page) {
            throw new NotFoundHttpException();
        }

        return $this->render('@Encounter/glossary/mts_diagram.html.twig', $page);
    }
}
