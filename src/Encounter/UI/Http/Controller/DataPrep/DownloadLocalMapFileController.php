<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\LocalMapFiles;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Download of the empty local map template or the example (#1130). The hospital fills in its own
 * codes locally, in a text editor or in the tool; they never reach the server.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final readonly class DownloadLocalMapFileController
{
    public function __construct(
        private LocalMapFiles $files,
    ) {
    }

    #[Route('/tools/data-prep/local-map-{variant}.json', name: 'app_tools_data_prep_local_map', requirements: ['variant' => 'template|example'], methods: ['GET'])]
    public function __invoke(string $variant): Response
    {
        return new Response($this->files->content($variant), Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->files->fileName($variant)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
