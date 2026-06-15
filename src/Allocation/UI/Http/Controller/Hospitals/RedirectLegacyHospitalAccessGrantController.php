<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class RedirectLegacyHospitalAccessGrantController extends AbstractController
{
    #[Route('/hospitals/{id}/access-grants', name: 'app_hospitals_access_grants', methods: ['GET'])]
    public function list(Hospital $hospital): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_hospitals_edit_access', ['id' => $hospital->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/hospitals/{id}/access-grants/new', name: 'app_hospitals_access_grants_new', methods: ['GET'])]
    public function create(Hospital $hospital): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_hospitals_edit_access_new', ['id' => $hospital->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/hospitals/{id}/access-grants/{grantId}/edit', name: 'app_hospitals_access_grants_edit', methods: ['GET'])]
    public function edit(Hospital $hospital, int $grantId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_hospitals_edit_access_grant', [
            'id' => $hospital->getId(),
            'grantId' => $grantId,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
