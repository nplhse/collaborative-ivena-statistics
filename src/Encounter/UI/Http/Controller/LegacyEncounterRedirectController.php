<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Keeps the pre-#1128 encounter URLs working after the review, the validation and the data preparation
 * moved into the Tools section (ADR 031) and the encounter overview page was removed. Every old route
 * name and path redirects permanently (308, so forms keep method and body) to its tool route, with the
 * query string. Access is checked by the target; `/encounter` itself leads to the Tools landing page.
 *
 * @todo Remove in v0.4-beta, see #1160.
 */
final class LegacyEncounterRedirectController extends AbstractController
{
    private const string TARGET = '_legacy_target';

    #[Route('/encounter', name: 'app_encounter_index', defaults: [self::TARGET => 'app_tools_index'], methods: ['GET'])]
    #[Route('/encounter/review', name: 'app_encounter_review_index', defaults: [self::TARGET => 'app_tools_encounter_review'], methods: ['GET'])]
    #[Route('/encounter/review/{id}', name: 'app_encounter_review_hospital', requirements: ['id' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_hospital'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/overview', name: 'app_encounter_review_overview', requirements: ['id' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_overview'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/visits', name: 'app_encounter_review_visits', requirements: ['id' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_visits'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/start', name: 'app_encounter_review_start', requirements: ['id' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_start'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/allocations/{allocationId}', name: 'app_encounter_review_allocation', requirements: ['id' => '\d+', 'allocationId' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_allocation'], methods: ['GET', 'POST'])]
    #[Route('/encounter/review/{id}/allocations/{allocationId}/skip', name: 'app_encounter_review_skip', requirements: ['id' => '\d+', 'allocationId' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_skip'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/allocations/{allocationId}/swap', name: 'app_encounter_review_swap', requirements: ['id' => '\d+', 'allocationId' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_review_swap'], methods: ['POST'])]
    #[Route('/encounter/review/{id}/validation', name: 'app_encounter_validation', requirements: ['id' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_validation'], methods: ['GET', 'POST'])]
    #[Route('/encounter/review/{id}/validation/{sampleId}', name: 'app_encounter_validation_sample', requirements: ['id' => '\d+', 'sampleId' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_validation_sample'], methods: ['GET'])]
    #[Route('/encounter/review/{id}/validation/{sampleId}/items/{itemId}', name: 'app_encounter_validation_item', requirements: ['id' => '\d+', 'sampleId' => '\d+', 'itemId' => '\d+'], defaults: [self::TARGET => 'app_tools_encounter_validation_item'], methods: ['GET', 'POST'])]
    #[Route('/encounter/data-prep', name: 'app_encounter_data_prep', defaults: [self::TARGET => 'app_tools_data_prep'], methods: ['GET'])]
    #[Route('/encounter/data-prep/key', name: 'app_encounter_data_prep_key', defaults: [self::TARGET => 'app_tools_data_prep_key'], methods: ['GET'])]
    #[Route('/encounter/data-prep/local-map', name: 'app_encounter_data_prep_local_map_help', defaults: [self::TARGET => 'app_tools_data_prep_local_map_help'], methods: ['GET'])]
    #[Route('/encounter/data-prep/local-map-{variant}.json', name: 'app_encounter_data_prep_local_map', requirements: ['variant' => 'template|example'], defaults: [self::TARGET => 'app_tools_data_prep_local_map'], methods: ['GET'])]
    #[Route('/encounter/data-prep/download', name: 'app_encounter_data_prep_download', defaults: [self::TARGET => 'app_tools_data_prep_download'], methods: ['GET'])]
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var array<string, string> $parameters */
        $parameters = $request->attributes->all('_route_params');
        $target = $parameters[self::TARGET];
        unset($parameters[self::TARGET]);

        return $this->redirectToRoute($target, [...$request->query->all(), ...$parameters], Response::HTTP_PERMANENTLY_REDIRECT);
    }
}
