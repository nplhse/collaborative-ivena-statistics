<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\MciCases;

use App\Allocation\Domain\Entity\MciCase;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowMciCaseController extends AbstractController
{
    #[Route(
        '/explore/mci_case/{publicId}',
        name: 'app_explore_mci_case_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function index(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] MciCase $mciCase,
    ): Response {
        return $this->render('@Allocation/mci_cases/show.html.twig', [
            'mciCase' => $mciCase,
        ]);
    }
}
