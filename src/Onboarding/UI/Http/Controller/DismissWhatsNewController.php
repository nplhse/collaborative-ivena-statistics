<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Http\Controller;

use App\Onboarding\Application\WhatsNew\WhatsNewService;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hides the "What's new for you" card of a release for the current user (#1485).
 */
final class DismissWhatsNewController extends AbstractController
{
    public function __construct(
        private readonly WhatsNewService $whatsNewService,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        '/whats-new/{releaseKey}/dismiss',
        name: 'app_whats_new_dismiss',
        requirements: ['releaseKey' => '[a-z0-9.\-]{1,20}'],
        methods: ['POST'],
    )]
    public function __invoke(string $releaseKey, Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('whats_new_dismiss_'.$releaseKey, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->whatsNewService->dismiss($user, $releaseKey);

        return $this->redirectToRoute('app_default');
    }
}
