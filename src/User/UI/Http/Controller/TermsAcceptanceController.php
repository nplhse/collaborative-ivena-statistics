<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\Terms\TermsAcceptanceFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use App\User\Infrastructure\Security\Terms\RequireTermsAcceptanceListener;
use App\User\Infrastructure\Security\Terms\TermsAcceptanceStatus;
use App\User\UI\Form\TermsAcceptanceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Acceptance of a new version of the terms of use (#1564, ADR 043). `RequireTermsAcceptanceListener` sends users here
 * until they accept; afterwards they return to the page they asked for. Whoever confirmed the upload authority for
 * hospitals under an earlier version can renew it here in the same step (#1565).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TermsAcceptanceController extends AbstractController
{
    public function __construct(
        private readonly TermsVersion $termsVersion,
        private readonly TermsAcceptanceStatus $status,
        private readonly TermsAcceptanceFactory $acceptanceFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly TermsAcceptanceRepository $acceptances,
    ) {
    }

    #[Route('/terms/accept', name: RequireTermsAcceptanceListener::ACCEPT_ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        if (!$this->status->isOutstanding($user, $session)) {
            return $this->redirect($this->targetPath($request));
        }

        $version = $this->termsVersion->current();
        $renewable = null === $version ? [] : $this->acceptances->hospitalsWithUploadAuthorityToRenew($user, $version);
        $form = $this->createForm(TermsAcceptanceType::class, null, [
            'upload_authority_hospitals' => array_map(static fn (Hospital $hospital): string => (string) $hospital->getName(), $renewable),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($this->acceptanceFactory->forCurrentTerms($user, $request->getLocale()));
            // Renews the upload authority for the new version in the same step (#1565); otherwise asked at the next upload.
            if ($form->has('uploadAuthority') && true === $form->get('uploadAuthority')->getData()) {
                foreach ($renewable as $hospital) {
                    $this->entityManager->persist($this->acceptanceFactory->forUploadAuthority($user, $hospital, $request->getLocale()));
                }
            }
            $this->entityManager->flush();
            $this->status->remember($user, $session);

            return $this->redirect($this->targetPath($request));
        }

        return $this->render('@User/terms/accept.html.twig', [
            'form' => $form,
            'version' => $version,
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
