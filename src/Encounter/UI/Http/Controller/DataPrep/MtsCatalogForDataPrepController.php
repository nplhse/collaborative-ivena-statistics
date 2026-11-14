<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\Application\Catalog\MtsCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public MTS catalog for the offline data preparation tool (ADR 032, #1157): active diagrams and indicators with
 * their aliases, in the tool's catalog format (MtsCatalog::toolCatalog()). The tool keeps its bundled copy as offline
 * fallback.
 *
 * Read only: the endpoint never creates catalog entries; only a successful import does. Like the update check, it
 * allows every origin, needs no session and sets no cookie (stateless route, `data_prep` firewall).
 */
final readonly class MtsCatalogForDataPrepController
{
    public const int MAX_AGE = 300;

    public function __construct(
        private MtsCatalog $catalog,
    ) {
    }

    #[Route('/data-prep/mts-catalog.json', name: 'app_encounter_data_prep_mts_catalog', methods: ['GET'], stateless: true)]
    public function __invoke(Request $request): JsonResponse
    {
        $response = new JsonResponse($this->catalog->toolCatalog());
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
