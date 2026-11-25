<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\MyRequests\MyRequestsProviderInterface;
use App\Shared\Application\MyRequests\MyRequestsSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My requests" (#1635): the requests the signed-in participant made in every context that contributes a section.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class MyRequestsController extends AbstractController
{
    /**
     * @param iterable<MyRequestsProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(MyRequestsProviderInterface::TAG)]
        private readonly iterable $providers,
    ) {
    }

    #[Route('/requests', name: 'app_my_requests', methods: ['GET'])]
    public function __invoke(): Response
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            $section = $provider->section();
            if ($section instanceof MyRequestsSection) {
                $sections[] = $section;
            }
        }

        usort($sections, static fn (MyRequestsSection $a, MyRequestsSection $b): int => $a->weight <=> $b->weight);

        return $this->render('@Shared/my_requests/index.html.twig', [
            'sections' => $sections,
        ]);
    }
}
