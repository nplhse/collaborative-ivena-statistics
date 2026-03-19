<?php

namespace App\Allocation\UI\Http\Controller\MciCases;

use App\Allocation\Domain\Entity\MciCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowMciCaseController extends AbstractController
{
    #[Route('/explore/mci_case/{id}', name: 'app_explore_mci_case_show')]
    public function index(MciCase $mciCase): Response
    {
        return $this->render('@Allocation/mci_cases/show.html.twig', [
            'mciCase' => $mciCase,
        ]);
    }
}
