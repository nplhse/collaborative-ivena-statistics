<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class ListOwnedHospitalsController extends AbstractController
{
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
    ) {
    }

    #[Route('/hospitals', name: 'app_hospitals_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        return $this->render('@Allocation/hospitals/owned_list.html.twig', [
            'hospitals' => $this->hospitalRepository->findOwnedByUser($user),
        ]);
    }
}
