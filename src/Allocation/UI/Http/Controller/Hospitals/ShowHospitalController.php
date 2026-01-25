<?php

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowHospitalController extends AbstractController
{
    #[Route('/explore/hospital/{id}', name: 'app_explore_hospital_show')]
    public function index(Hospital $hospital): Response
    {
        return $this->render('@Allocation/hospitals/show.html.twig', [
            'hospital' => $hospital,
        ]);
    }
}
