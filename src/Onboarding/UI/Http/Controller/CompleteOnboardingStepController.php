<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Http\Controller;

use App\Onboarding\Application\OnboardingProgressService;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CompleteOnboardingStepController extends AbstractController
{
    public function __construct(
        private readonly OnboardingProgressService $onboardingProgressService,
    ) {
    }

    #[IsGranted('ROLE_PARTICIPANT')]
    #[Route(
        '/onboarding/steps/{stepKey}/complete',
        name: 'app_onboarding_step_complete',
        methods: ['POST'],
    )]
    public function __invoke(
        string $stepKey,
        Request $request,
        #[CurrentUser] User $user,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $key = OnboardingStepKey::tryFrom($stepKey);
        if (!$key instanceof OnboardingStepKey) {
            throw new NotFoundHttpException();
        }

        if (!$this->isCsrfTokenValid('onboarding_complete_'.$stepKey, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->onboardingProgressService->markCompleted($user, $key);

        $referer = $request->headers->get('referer');
        if (\is_string($referer) && '' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_default');
    }
}
