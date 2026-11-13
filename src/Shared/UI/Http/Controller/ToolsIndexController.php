<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\Navigation\Tool\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing page of the Tools section (ADR 031): every interactive workflow the user may use.
 */
#[IsGranted('ROLE_USER')]
final class ToolsIndexController extends AbstractController
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {
    }

    #[Route('/tools', name: 'app_tools_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Shared/tools/index.html.twig', [
            'groups' => $this->toolRegistry->groupedByCategory(),
        ]);
    }
}
