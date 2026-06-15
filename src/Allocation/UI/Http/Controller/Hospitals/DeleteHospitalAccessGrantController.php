<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Infrastructure\Security\Voter\HospitalVoter;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class DeleteHospitalAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/hospitals/{id}/edit/access/{grantId}/delete', name: 'app_hospitals_edit_access_grant_delete', requirements: ['grantId' => '\d+'], methods: ['POST'])]
    #[Route('/hospitals/{id}/access-grants/{grantId}/delete', name: 'app_hospitals_access_grants_delete', requirements: ['grantId' => '\d+'], methods: ['POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $grantId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted(HospitalVoter::MANAGE_ACCESS_GRANTS, $hospital);

        if (!$this->isCsrfTokenValid('delete_access_grant_'.$grantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $grant = $this->entityManager->getRepository(HospitalAccessGrant::class)->find($grantId);
        if (!$grant instanceof HospitalAccessGrant || $grant->getHospital()?->getId() !== $hospital->getId()) {
            throw $this->createNotFoundException('Access grant not found.');
        }

        $this->entityManager->remove($grant);
        $this->auditContext->beginIntent('hospital.access_grant.deleted', [
            'hospital_id' => $hospital->getId(),
            'grant_id' => $grantId,
        ]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->addFlash('success', 'flash.hospital_access_grant.deleted');

        return $this->redirectToRoute('app_hospitals_edit_access', ['id' => $hospital->getId()]);
    }
}
