<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepRelease;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public version information for the update check of the offline tool (ADR 025, #1051).
 *
 * The tool runs from a local file, so the response allows every origin. It carries no user data,
 * needs no session and sets no cookie (stateless route).
 */
final readonly class LatestDataPrepVersionController
{
    public function __construct(
        private DataPrepRelease $release,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/data-prep/latest.json', name: 'app_encounter_data_prep_latest', methods: ['GET'], stateless: true)]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse([
            'version' => $this->release->version,
            'minVersion' => $this->release->minVersion,
            'releasedAt' => $this->release->releasedAt,
            'downloadUrl' => $this->urlGenerator->generate('app_encounter_data_prep_download', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
