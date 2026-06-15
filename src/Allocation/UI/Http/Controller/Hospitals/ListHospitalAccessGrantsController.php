<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Allocation\Infrastructure\Security\Voter\HospitalVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class ListHospitalAccessGrantsController extends AbstractController
{
    public function __construct(
        private readonly HospitalAccessGrantRepository $hospitalAccessGrantRepository,
    ) {
    }

    #[Route('/hospitals/{id}/edit/access', name: 'app_hospitals_edit_access', methods: ['GET'])]
    public function __invoke(Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(HospitalVoter::MANAGE_ACCESS_GRANTS, $hospital);

        $grants = $this->hospitalAccessGrantRepository->findForHospital($hospital);

        return $this->render('@Allocation/hospitals/access/index.html.twig', [
            'hospital' => $hospital,
            'grants' => $grants,
            'assignablePermissions' => HospitalPermission::assignableCases(),
        ]);
    }
}
