<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Domain\Entity\IndicationRaw;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class AssignIndicationRawController extends AbstractController
{
    #[Route(
        '/explore/indication/raw/assign/{publicId}',
        name: 'app_explore_indication_raw_assign',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] IndicationRaw $raw,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        return $this->redirectToRoute('app_explore_indication_raw_review', ['publicId' => $raw->getPublicIdString()]);
    }
}
