<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\AllocationList;

use App\Encounter\Application\Display\EncounterListDetailRegistry;
use App\Encounter\UI\AllocationList\EncounterCaseHospitals;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Details of the column "Encounter" of the Explore allocation list (#1203), loaded after the page has rendered:
 * one request for every linked encounter of a page. Each encounter is checked by its own hospital (VIEW_CASES,
 * ADR 028); ids the user may not see are dropped silently, so the answer reveals nothing about them.
 *
 * Answers `{"details": {"<encounter id>": "<html>"}}`; encounters without details are missing.
 */
final class EncounterListDetailsController extends AbstractController
{
    /** At most one page of the list (100 rows) per request. */
    public const int MAX_ENCOUNTERS = 100;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly EncounterCaseHospitals $caseHospitals,
        private readonly EncounterListDetailRegistry $details,
    ) {
    }

    #[Route('/explore/allocation/encounter-details', name: 'app_explore_allocation_encounter_details', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $requested = $this->encounterIds($request->query->getString('encounters'));
        $visible = $user instanceof User ? $this->caseHospitals->visibleEncounters($user, $requested) : [];

        $html = [];
        foreach ($this->details->forEncounters($visible) as $encounterId => $details) {
            $html[(string) $encounterId] = $this->renderView('@Encounter/allocation_list/_details.html.twig', ['details' => $details]);
        }

        $response = new JsonResponse(['details' => (object) $html]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @return list<int>
     */
    private function encounterIds(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = filter_var(trim($part), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (\is_int($id) && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return \array_slice($ids, 0, self::MAX_ENCOUNTERS);
    }
}
