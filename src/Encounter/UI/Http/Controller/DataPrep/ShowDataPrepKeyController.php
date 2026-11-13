<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepHospitals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Key file page of the data preparation tool (#1130, ADR 027): what the key is for, key generations, a
 * lost key; the key change link only for hospitals with CHANGE_KEY (owner, administrator).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowDataPrepKeyController extends AbstractController
{
    public function __construct(
        private readonly DataPrepHospitals $hospitals,
    ) {
    }

    #[Route('/tools/data-prep/key', name: 'app_tools_data_prep_key', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Encounter/data_prep/key.html.twig', [
            'hospitals' => $this->hospitals->forCurrentUser(),
        ]);
    }
}
