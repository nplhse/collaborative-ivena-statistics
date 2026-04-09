<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\UI\Form\HospitalParticipantAddressEditType;
use App\Allocation\UI\Form\HospitalParticipantEditType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class EditOwnedHospitalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/hospitals/{id}/edit', name: 'app_hospitals_edit', methods: ['GET', 'POST'])]
    public function editGeneral(Request $request, Hospital $hospital): Response
    {
        return $this->handleEdit(
            $request,
            $hospital,
            HospitalParticipantEditType::class,
            'general'
        );
    }

    #[Route('/hospitals/{id}/edit/address', name: 'app_hospitals_edit_address', methods: ['GET', 'POST'])]
    public function editAddress(Request $request, Hospital $hospital): Response
    {
        return $this->handleEdit(
            $request,
            $hospital,
            HospitalParticipantAddressEditType::class,
            'address'
        );
    }

    /**
     * @phpstan-param class-string<FormTypeInterface<mixed>> $formType
     *
     * @psalm-param class-string<FormTypeInterface> $formType
     */
    private function handleEdit(Request $request, Hospital $hospital, string $formType, string $section): Response
    {
        $this->assertHospitalOwnership($hospital);

        $form = $this->createForm($formType, $hospital);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->auditContext->beginIntent('hospital.participant.updated', ['section' => $section]);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', 'flash.hospital.updated');

            return $this->redirectToRoute('app_explore_hospital_show', ['id' => $hospital->getId()]);
        }

        return $this->render('@Allocation/hospitals/edit_owned.html.twig', [
            'hospital' => $hospital,
            'form' => $form,
            'activeSection' => $section,
        ]);
    }

    private function assertHospitalOwnership(Hospital $hospital): void
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        if ($hospital->getOwner()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Only owners can edit this hospital.');
        }
    }
}
