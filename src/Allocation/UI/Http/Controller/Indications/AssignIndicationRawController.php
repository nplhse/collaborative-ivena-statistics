<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Domain\Entity\IndicationRaw;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class AssignIndicationRawController extends AbstractController
{
    #[Route('/explore/indication/raw/assign/{id}', name: 'app_explore_indication_raw_assign', methods: ['GET', 'POST'])]
    public function edit(IndicationRaw $raw): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_explore_indication_raw_review', ['id' => $raw->getId()]);
    }
}
