<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Map shapes of the dispatch areas. Private, because `neighbour` shows which areas have hospitals (ADR 041).
 */
final readonly class DispatchAreaShapesController
{
    public function __construct(
        private DispatchAreaGeoIndexInterface $geoIndex,
    ) {
    }

    #[Route('/statistics/geo/dispatch-areas.geojson', name: 'app_stats_geo_dispatch_areas', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $shapes = $this->geoIndex->geoJson();

        $response = new Response();
        $response->setEtag($shapes->etag);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($shapes->content);
        $response->headers->set('Content-Type', 'application/geo+json');

        return $response;
    }
}
