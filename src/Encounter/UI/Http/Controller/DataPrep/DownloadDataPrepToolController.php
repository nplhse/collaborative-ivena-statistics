<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\Encounter\UI\DataPrep\DataPrepToolRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Download of the offline data preparation tool (ADR 026, #1051) for the pilot hospitals.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final readonly class DownloadDataPrepToolController
{
    public function __construct(
        private DataPrepToolRenderer $renderer,
        private DataPrepRelease $release,
    ) {
    }

    #[Route('/encounter/data-prep/download', name: 'app_encounter_data_prep_download', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->renderer->render(), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->release->fileName()),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
