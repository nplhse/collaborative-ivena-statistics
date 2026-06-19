<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Allocation\Infrastructure\Security\Voter\HospitalVoter;
use App\Allocation\UI\Form\HospitalAccessGrantType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class NewHospitalAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly HospitalAccessGrantRepository $hospitalAccessGrantRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/hospitals/{id}/edit/access/new', name: 'app_hospitals_edit_access_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(HospitalVoter::MANAGE_ACCESS_GRANTS, $hospital);

        $grants = $this->hospitalAccessGrantRepository->findForHospital($hospital);
        $userDatalist = $this->userRepository->findGrantEligibleUserDatalist(
            $hospital,
            $this->resolveGrantedUserIds($grants),
        );

        $grant = new HospitalAccessGrant()
            ->setHospital($hospital);

        $user = $this->getUser();
        if ($user instanceof User) {
            $grant->setCreatedBy($user);
        }

        $form = $this->createForm(HospitalAccessGrantType::class, $grant, [
            'is_create' => true,
            'eligible_user_choices' => $userDatalist,
        ]);
        $form->handleRequest($request);

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedUser = $grant->getUser();
            if (!$selectedUser instanceof User) {
                throw $this->createAccessDeniedException('User is required.');
            }

            if ($this->hospitalAccessGrantRepository->existsForUserAndHospital($selectedUser, $hospital)) {
                $this->addFlash('error', 'flash.hospital_access_grant.duplicate');

                return $this->redirectToRoute('app_hospitals_edit_access_new', ['id' => $hospital->getId()]);
            }

            $this->entityManager->persist($grant);
            $this->auditContext->beginIntent('hospital.access_grant.created', ['hospital_id' => $hospital->getId()]);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', 'flash.hospital_access_grant.created');

            return $this->redirectToRoute('app_hospitals_edit_access', ['id' => $hospital->getId()]);
        }

        return $this->render('@Allocation/hospitals/access/new.html.twig', [
            'hospital' => $hospital,
            'userDatalist' => $userDatalist,
            'form' => $form,
        ]);
    }

    /**
     * @param list<HospitalAccessGrant> $grants
     *
     * @return list<int>
     */
    private function resolveGrantedUserIds(array $grants): array
    {
        $ids = [];
        foreach ($grants as $grant) {
            $userId = $grant->getUser()?->getId();
            if (null !== $userId) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }
}
