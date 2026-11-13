<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepChangelog;
use App\Encounter\UI\DataPrep\DataPrepHospitals;
use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\Encounter\UI\DataPrep\DataPrepToolRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing page of the offline data preparation tool (#1130): getting started from the grants to the
 * first upload, short summaries of the key file and the local map with links to their pages, the
 * current version and the changelog. The page shows no case data and asks for no hospital codes.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowDataPrepGuideController extends AbstractController
{
    public function __construct(
        private readonly DataPrepRelease $release,
        private readonly DataPrepChangelog $changelog,
        private readonly DataPrepHospitals $hospitals,
        private readonly DataPrepToolRenderer $toolRenderer,
    ) {
    }

    #[Route('/encounter/data-prep', name: 'app_encounter_data_prep', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Encounter/data_prep/guide.html.twig', [
            'release' => $this->release,
            'changelog' => $this->changelog->entries(),
            'profiles' => $this->toolRenderer->profileSummaries(),
            'hospitals' => $this->hospitals->forCurrentUser(),
        ]);
    }
}
