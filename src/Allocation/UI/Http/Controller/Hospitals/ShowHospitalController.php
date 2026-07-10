<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowHospitalController extends AbstractController
{
    #[Route(
        '/explore/hospital/{publicId}',
        name: 'app_explore_hospital_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function index(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Hospital $hospital,
    ): Response {
        return $this->render('@Allocation/hospitals/show.html.twig', [
            'hospital' => $hospital,
        ]);
    }
}
