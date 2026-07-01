<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Infrastructure\Security\Voter\HospitalVoter;
use App\Allocation\UI\Form\HospitalAccessGrantType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_PARTICIPANT')]
final class EditHospitalAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/hospitals/{id}/edit/access/{grantId}', name: 'app_hospitals_edit_access_grant', requirements: ['grantId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $grantId): Response
    {
        $this->denyAccessUnlessGranted(HospitalVoter::MANAGE_ACCESS_GRANTS, $hospital);

        $grant = $this->findGrantForHospital($hospital, $grantId);

        $form = $this->createForm(HospitalAccessGrantType::class, $grant, [
            'is_create' => false,
        ]);
        $form->handleRequest($request);

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if ($user instanceof User) {
                $grant->setUpdatedBy($user);
            }

            $this->auditContext->beginIntent('hospital.access_grant.updated', [
                'hospital_id' => $hospital->getId(),
                'grant_id' => $grantId,
            ]);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.hospital_access_grant.updated', domain: 'allocation'));

            return $this->redirectToRoute('app_hospitals_edit_access', ['id' => $hospital->getId()]);
        }

        return $this->render('@Allocation/hospitals/access/edit.html.twig', [
            'hospital' => $hospital,
            'grant' => $grant,
            'form' => $form,
        ]);
    }

    private function findGrantForHospital(Hospital $hospital, int $grantId): HospitalAccessGrant
    {
        foreach ($hospital->getAccessGrants() as $grant) {
            if ($grant->getId() === $grantId) {
                return $grant;
            }
        }

        $grant = $this->entityManager->getRepository(HospitalAccessGrant::class)->find($grantId);
        if (!$grant instanceof HospitalAccessGrant || $grant->getHospital()?->getId() !== $hospital->getId()) {
            throw $this->createNotFoundException('Access grant not found.');
        }

        return $grant;
    }
}
