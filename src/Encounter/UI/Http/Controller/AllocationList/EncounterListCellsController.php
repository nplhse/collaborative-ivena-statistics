<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\AllocationList;

use App\Encounter\Application\Display\EncounterListColumnRegistry;
use App\Encounter\UI\AllocationList\EncounterCaseHospitals;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cells of the encounter schema columns of the Explore allocation list (#1203), loaded after the page has rendered:
 * one request for every schema cell of a page. Each encounter is checked by its own hospital (VIEW_CASES,
 * ADR 028); ids the user may not see are dropped silently, so the answer reveals nothing about them. Unknown
 * columns are ignored.
 *
 * Answers `{"cells": {"<encounter id>": {"<column>": "<html>"}}}`; cells without a value are missing.
 */
final class EncounterListCellsController extends AbstractController
{
    /** At most one page of the list (100 rows) per request. */
    public const int MAX_ENCOUNTERS = 100;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly EncounterCaseHospitals $caseHospitals,
        private readonly EncounterListColumnRegistry $columns,
    ) {
    }

    #[Route('/explore/allocation/encounter-cells', name: 'app_explore_allocation_encounter_cells', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $requested = array_slice($this->ids($request->query->getString('encounters')), 0, self::MAX_ENCOUNTERS);
        $visible = $user instanceof User ? $this->caseHospitals->visibleEncounters($user, $requested) : [];
        $columns = array_values(array_intersect($this->keys($request->query->getString('columns')), array_keys($this->columns->columns())));

        $html = [];
        foreach ($this->columns->cells($visible, $columns) as $column => $byEncounter) {
            foreach ($byEncounter as $encounterId => $cell) {
                $html[(string) $encounterId][$column] = $this->renderView('@Encounter/allocation_list/_schema_value.html.twig', ['cell' => $cell]);
            }
        }

        $response = new JsonResponse(['cells' => (object) $html]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @return list<int>
     */
    private function ids(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = filter_var(trim($part), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (\is_int($id) && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function keys(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $value)))));
    }
}
