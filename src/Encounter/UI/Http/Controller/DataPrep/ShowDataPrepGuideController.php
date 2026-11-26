<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepChangelog;
use App\Encounter\UI\DataPrep\DataPrepHospitals;
use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\Encounter\UI\DataPrep\DataPrepToolRenderer;
use App\Import\Application\Service\ImportUploadTargets;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
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
        private readonly ImportUploadTargets $uploadTargets,
    ) {
    }

    #[Route('/tools/data-prep', name: 'app_tools_data_prep', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();

        return $this->render('@Encounter/data_prep/guide.html.twig', [
            'release' => $this->release,
            'changelog' => $this->changelog->entries(),
            'profiles' => $this->toolRenderer->profileSummaries(),
            'hospitals' => $this->hospitals->forCurrentUser(),
            'review_url' => $this->reviewUrl(),
            // The upload button only for users who can upload encounter data somewhere (#1351).
            'can_upload' => $user instanceof User && $this->uploadTargets->canUpload($user, ImportType::ENCOUNTER),
        ]);
    }

    /**
     * The match review belongs to the ED visit plugin; without it the guide links no review (#1300).
     */
    private function reviewUrl(): ?string
    {
        try {
            return $this->generateUrl('app_tools_encounter_review');
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
