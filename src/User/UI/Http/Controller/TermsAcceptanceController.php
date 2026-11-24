<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\User\Application\Terms\TermsAcceptanceFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\Terms\RequireTermsAcceptanceListener;
use App\User\Infrastructure\Security\Terms\TermsAcceptanceStatus;
use App\User\UI\Form\TermsAcceptanceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Acceptance of a new version of the terms of use (#1564, ADR 043). `RequireTermsAcceptanceListener` sends users here
 * until they accept; afterwards they return to the page they asked for.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TermsAcceptanceController extends AbstractController
{
    public function __construct(
        private readonly TermsVersion $termsVersion,
        private readonly TermsAcceptanceStatus $status,
        private readonly TermsAcceptanceFactory $acceptanceFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/terms/accept', name: RequireTermsAcceptanceListener::ACCEPT_ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $session = $request->getSession();
        if (!$this->status->isOutstanding($user, $session)) {
            return $this->redirect($this->targetPath($request));
        }

        $form = $this->createForm(TermsAcceptanceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($this->acceptanceFactory->forCurrentTerms($user, $request->getLocale()));
            $this->entityManager->flush();
            $this->status->remember($user, $session);

            return $this->redirect($this->targetPath($request));
        }

        return $this->render('@User/terms/accept.html.twig', [
            'form' => $form,
            'version' => $this->termsVersion->current(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** The page the user asked for before being sent here; only paths of this site. */
    private function targetPath(Request $request): string
    {
        $target = $request->getSession()->remove(RequireTermsAcceptanceListener::TARGET_PATH_KEY);
        if (\is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\')) {
            return $target;
        }

        return $this->generateUrl('app_default');
    }
}
